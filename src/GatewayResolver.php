<?php

namespace FlysystemIpfs;

use LogicException;

class GatewayResolver
{
    public const DEFAULT_GATEWAY = 'ipfs.io';

    protected string $service;

    protected string $style;

    protected ?string $url = null;

    protected ?string $domain = null;

    protected bool $preferDomain;

    public function __construct(string $service, string $style, array $parameters = [])
    {
        $this->setService($service);
        $this->setStyle($style);

        $this->url = isset($parameters['url']) ? trim($parameters['url'], '/') : self::DEFAULT_GATEWAY;
        $this->domain = (isset($parameters['domain'])) ? trim($parameters['domain'], '/') : null;
        $this->preferDomain = $parameters['prefer_domain'] ?? true;
    }

    public function getService(): string
    {
        return $this->service;
    }

    public function setService(string $service): GatewayResolver
    {
        if (! in_array(strtolower($service), ['ipfs', 'ipns'])) {
            throw new LogicException(get_class($this).' does not support service: '.$service);
        }

        $this->service = strtolower($service);

        return $this;
    }

    public function getStyle(): string
    {
        return $this->style;
    }

    public function setStyle(string $style): GatewayResolver
    {
        if (! in_array(strtolower($style), ['path', 'subdomain', 'dnslink'])) {
            throw new LogicException(get_class($this).' does not support style: '.$style);
        }

        $this->style = strtolower($style);

        return $this;
    }

    /**
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function resolve(?string $path, ?string $file, ?string $cid, ?string $ipns): string
    {
        if ($this->service === 'ipfs' && $this->style !== 'dnslink' && is_null($cid)) {
            throw new LogicException(get_class($this).' with service "ipfs" and style "'.$this->style.'" requires a CID.');
        }

        if ($this->service === 'ipns' && $this->style !== 'dnslink' && is_null($ipns)) {
            throw new LogicException(get_class($this).' with service "ipns" and style "'.$this->style.'" requires a IPNS identifier.');
        }

        if ($this->style === 'dnslink' && is_null($this->domain)) {
            throw new LogicException(get_class($this).' with style "'.$this->style.'" requires a domain.');
        }

        $identifier = ($this->service === 'ipfs') ? $cid : $ipns;

        return sprintf(
            'https://%1$s/%2$s',
            $this->resolveDomain($identifier),
            $this->resolvePath($path, $file, $identifier)
        );
    }

    private function resolveDomain(?string $identifier): string
    {
        $domain = $this->url;

        if ($this->style === 'dnslink' && $this->preferDomain === true) {
            $domain = $this->domain;
        }

        if ($this->style === 'subdomain') {
            $domain = "$identifier.$this->service.$this->url";
        }

        /* @phpstan-ignore-next-line */
        return $domain;
    }

    private function resolvePath(?string $path, ?string $file, ?string $identifier): string
    {
        $resourcePath = trim($path ?? '', '/').'/'.trim($file ?? '', '/');

        $urlPath = '';
        switch ($this->style) {
            case 'path':
                $urlPath = "$this->service/$identifier/".trim($file ?? '', '/');
                break;
            case 'dnslink':
                if (! is_null($this->url) && $this->preferDomain === false) {
                    $urlPath .= "ipns/$this->domain/";
                }
                $urlPath .= "$resourcePath";
                break;
            default:
                $urlPath = trim($file ?? '', '/');
        }

        return $urlPath;
    }
}
