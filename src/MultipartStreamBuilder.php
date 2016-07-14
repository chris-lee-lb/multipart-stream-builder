<?php

namespace Http\Message;

use GuzzleHttp\Psr7\AppendStream;
use Http\Discovery\StreamFactoryDiscovery;
use Http\Message\StreamFactory;
use Psr\Http\Message\StreamInterface;
use Zend\Diactoros\CallbackStream;

/**
 * Build your own Multipart stream. A Multipart stream is a collection of streams separated with a $bounary. This
 * class helps you to create a Multipart stream with stream implementations from any PSR7 library.
 *
 * @author Michael Dowling and contributors to guzzlehttp/psr7
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class MultipartStreamBuilder
{
    /**
     * @var StreamFactory
     */
    private $streamFactory;

    /**
     * @var string
     */
    private $boundary;

    /**
     * @var array Element where each Element is an array with keys ['contents', 'headers', 'filename']
     */
    private $data;

    /**
     * @param StreamFactory|null $streamFactory
     */
    public function __construct(StreamFactory $streamFactory = null)
    {
        $this->streamFactory = $streamFactory ?: StreamFactoryDiscovery::find();
    }

    /**
     * Add a resource to the Multipart Stream. If the same $name is used twice the first resource will
     * be overwritten.
     *
     * @param string $name the formpost name
     * @param string|resource|StreamInterface $resource
     * @param array $options         {
     *
     *     @var array $headers additional headers ['header-name' => 'header-value']
     *     @var string $filename
     * }
     * 
     * @return MultipartStreamBuilder
     */
    public function addResource($name, $resource, array $options = [])
    {
        $stream = $this->streamFactory->createStream($resource);

        // validate options['headers'] exists
        if (!isset($options['headers'])) {
            $options['headers'] = [];
        }

        // Try to add filename if it is missing
        if (empty($options['filename'])) {
            $options['filename'] = null;
            $uri = $stream->getMetadata('uri');
            if (substr($uri, 0, 6) !== 'php://') {
                $options['filename'] = $uri;
            }

        }

        $this->prepareHeaders($name, $stream, $options['filename'], $options['headers']);
        $this->data[$name] = ['contents' => $stream, 'headers' => $options['headers'], 'filename' => $options['filename']];
        
        return $this;
    }

    /**
     * Build the stream.
     *
     * @return StreamInterface
     */
    public function build()
    {
        $streams = '';
        foreach ($this->data as $data) {

            // Add start and headers
            $streams .= "--{$this->getBoundary()}\r\n".
                $this->getHeaders($data['headers'])."\r\n";

            // Convert the stream to string
            $streams .= (string) $data['contents'];
            $streams .= "\r\n";
        }

        // Append end
        $streams .= "--{$this->getBoundary()}--\r\n";

        return $this->streamFactory->createStream($streams);
    }

    /**
     * Add extra headers if they are missing
     *
     * @param string $name
     * @param StreamInterface $stream
     * @param string $filename
     * @param array &$headers
     */
    private function prepareHeaders($name, StreamInterface $stream, $filename, array &$headers)
    {
        // Set a default content-disposition header if one was no provided
        $disposition = $this->getHeader($headers, 'content-disposition');
        if (!$disposition) {
            $headers['Content-Disposition'] = ($filename === '0' || $filename)
                ? sprintf('form-data; name="%s"; filename="%s"',
                    $name,
                    basename($filename))
                : "form-data; name=\"{$name}\"";
        }

        // Set a default content-length header if one was no provided
        $length = $this->getHeader($headers, 'content-length');
        if (!$length) {
            if ($length = $stream->getSize()) {
                $headers['Content-Length'] = (string) $length;
            }
        }

        // Set a default Content-Type if one was not supplied
        $type = $this->getHeader($headers, 'content-type');
        if (!$type && ($filename === '0' || $filename)) {
            if ($type = MimetypeHelper::getMimetypeFromFilename($filename)) {
                $headers['Content-Type'] = $type;
            }
        }
    }

    /**
     * Get the headers formatted for the HTTP message.
     *
     * @param array $headers
     *
     * @return string
     */
    private function getHeaders(array $headers)
    {
        $str = '';
        foreach ($headers as $key => $value) {
            $str .= "{$key}: {$value}\r\n";
        }

        return $str;
    }

    /**
     * Get one header by its name.
     *
     * @param array $headers
     * @param string $key case insensitive
     *
     * @return string|null
     */
    private function getHeader(array $headers, $key)
    {
        $lowercaseHeader = strtolower($key);
        foreach ($headers as $k => $v) {
            if (strtolower($k) === $lowercaseHeader) {
                return $v;
            }
        }

        return;
    }

    /**
     * Get the boundary that separates the streams.
     *
     * @return string
     */
    public function getBoundary()
    {
        if ($this->boundary === null) {
            $this->boundary = uniqid();
        }

        return $this->boundary;
    }

    /**
     * @param string $boundary
     *
     * @return MultipartStreamBuilder
     */
    public function setBoundary($boundary)
    {
        $this->boundary = $boundary;

        return $this;
    }
}
