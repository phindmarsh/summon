<?php

namespace Summon;

use Guzzle\Http\Client,
    Guzzle\Http\Message\Response,
    Guzzle\Batch\BatchBuilder,
    Guzzle\Http\Message\Request,
    Guzzle\Http\Exception\ClientErrorResponseException,
    DOMDocument;

class Summon {

    const USER_AGENT = 'Mozilla/5.0 (compatible; Summon/1.0; +http://github.com/phindmarsh/summon)';
    const DEFAULT_TYPE = 'default';

    const MIN_FILESIZE = 4096;

    // images parsed from html should have roughly square ratio
    const MIN_RATIO = .5;
    const MAX_RATIO = 2;

    // but no more than this
    const MAX_IMAGES = 8;

    /**
     * @var array $PREFERRED_TIERS
     *
     * Describes the count to break on if the number of images that have an
     * area greater than the keyed size are present in the filtered array.
     * e.g. 100000 => 1 indicates there only needs to be one image with an area of 100000
     */
    private static $PREFERRED_TIERS = array(
        100000 => 1,
        50000 => 3,
        20000 => 4,
        5000 => 8
    );

    /**
     * @var Client $client
     */
    private $client;

    private $mimetype;
    private $url;


    private static $mimes = array(
        'image' => array(
            'image/png', 'image/jpg',
            'image/jpeg', 'image/pjpeg',
            'image/gif', 'image/svg+xml'
        ),
        'html' => array(
            'text/html', 'application/xhtml+xml'
        )
    );

    public function __construct($url){
        $this->url = $url;
        $this->client = new Client($this->url);
        $this->client->setUserAgent(self::USER_AGENT);
    }

    public static function create($url){
        if(stripos($url, 'http') !== 0) $url = 'http://' . $url;

        $instance = new self($url);
        return $instance;
    }

    /**
     * @return mixed
     * @throws SummonException
     * @throws \Guzzle\Http\Exception\ClientErrorResponseException
     */
    public function fetch(){

        try {
            $response = $this->client->head()
                ->send();
        }
        catch(ClientErrorResponseException $e){
            // HEAD method is not allowed, try GET instead
            $response = $this->client->get()->send();
        }

        // update the url to the actual endpoint (after redirects)
        $this->url = $response->getInfo('url');
        list($this->mimetype) = explode(';', $response->getContentType());

        $type = self::getType($this->mimetype);
        $handler = sprintf('parse%s', ucfirst($type));

        if(!method_exists($this, $handler)){
            throw new SummonException("Unable to handle type [$type] in self");
        }

        return $this->$handler($response);

    }

    private static function getType($mimetype){
        foreach(self::$mimes as $type => $mimes){
            if(in_array($mimetype, $mimes, true)){
                return ucfirst($type);
            }
        }
        return self::DEFAULT_TYPE;
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    private function parseDefault(){
        return $this->formatResponse(null);
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    private function parseImage(){
        return $this->formatResponse($this->url);
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    private function parseHtml(Response $response){

        $images = array();
        $entity_body = $response->getBody();
        if($entity_body->getContentLength() <= 0){
            $response = $this->client->get()->send();
            $entity_body = $response->getBody();
        }

        if($entity_body->getContentLength() <= 0)
            throw new SummonException("Could not read remote source");

        // suppress warnings about bad formatting
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML($entity_body->__toString());
        libxml_clear_errors();


        $fetched = array();
        $self = get_class();

        $batch = BatchBuilder::factory()
            ->transferRequests(1)
            ->autoFlushAt(10)
            ->notify(function($transferred) use (&$fetched, $self) {
                /** @var Request[] $transferred */
                foreach($transferred as $request){
                    $length = $request->getResponse()->getContentLength();
                    if($request->getResponse()->hasHeader('content-length') && $length < $self::MIN_FILESIZE) continue;

                    $img_data = @getimagesize($request->getResponse()->getBody()->getUri());
                    if(!is_array($img_data)) continue;

                    $image = array(
                        'url' => $request->getResponse()->getInfo('url'),
                        'length' => $length,
                        'width' => $img_data[0],
                        'height' => $img_data[1],
                        'ratio' => $img_data[0] / $img_data[1],
                        'area' => $img_data[0] * $img_data[1]
                    );

                    $image['aspect'] = ($image['ratio'] > $self::MIN_RATIO
                        && $image['ratio'] < $self::MAX_RATIO);

                    $fetched[] = $image;
                }
            })
            ->bufferExceptions()
            ->build();

        foreach ($doc->getElementsByTagName('img') as $img) {
            $src = self::resolveUrl($this->url, $img->getAttribute('src'));
            $key = md5($src);
            if(!isset($images[$key])){
                $images[$key] = true;
                $batch->add($this->client->get($src, null, tmpfile()));
            }
        }

        unset($doc);
        unset($images);
        $batch->flush();

        usort($fetched, function($a, $b){
            // if either is within the bounds but not both, the one that is wins
            if($a['aspect'] ^ $b['aspect']) return $a['aspect'] ? -1 : 1;
            // if none or both are then return the one with the larger area
            else return $b['area'] - $a['area'];
        });

        $filtered = array();
        $count = 0;
        foreach($fetched as $img){
            foreach(self::$PREFERRED_TIERS as $area => $limit){
                if($img['area'] >= $area){
                    $count++;
                    $filtered[] = $img['url'];
                    if($count >= $limit)
                        break 2;

                    break;
                }
            }
        }

        return $this->formatResponse($filtered);
    }

    /**
     * Transforms a URL into a fully qualified version based on the $base URL.
     *
     * @param string $base the base url to resolve from
     * @param string $path the url to be resolved relative to the base
     *
     * @return string the fully resolved URL
     */
    private static function resolveUrl($base, $path){
        $components = parse_url($base);
        $scheme = (isset($components['scheme']) ? $components['scheme'] : 'http') . '://';

        if(strpos($path, 'http://') === 0 || strpos($path, 'https://')){
            return $path;
        }
        elseif(strpos($path, '//') === 0){
            return  'http:'. $path;
        }
        // it is an absolute path so prefix it with the domain
        elseif(strpos($path, '/') === 0) {
            $host = $scheme . $components['host'];
            return $host . $path;
        }
        // it must be a relative path so append it to the nearest directory component
        else {
            $host = $scheme . $components['host'];
            $dir = dirname(isset($components['path']) ? $components['path'] : '/');

            return $host . $dir . '/' . $path;

        }
    }

    private function formatResponse($thumbnails){
        if($thumbnails !== null && !is_array($thumbnails))
            $thumbnails = array($thumbnails);

        return array(
            'source' => $this->url,
            'type' => $this->mimetype,
            'thumbnails' => $thumbnails
        );
    }

}
