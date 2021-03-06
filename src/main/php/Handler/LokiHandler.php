<?php

/**
 * Copyright (c) 2016 - 2020 Itspire.
 * This software is licensed under the BSD-3-Clause license. (see LICENSE.md for full license)
 * All Right Reserved.
 */

declare(strict_types=1);

namespace Itspire\MonologLoki\Handler;

use Itspire\MonologLoki\Formatter\LokiFormatter;
use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\Curl;
use Monolog\Logger;

class LokiHandler extends AbstractProcessingHandler
{
    /** the scheme, hostname and port to the Loki system */
    protected ?string $entrypoint;

    /** the identifiers for Basic Authentication to the Loki system */
    protected array $basicAuth = [];

    /** the name of the system (hostname) sending log messages to Loki */
    protected ?string $systemName;

    /** the list of default context variables to be sent to the Loki system */
    protected array $globalContext = [];

    /** the list of default labels to be sent to the Loki system */
    protected array $globalLabels = [];

    public function __construct(array $apiConfig, $level = Logger::DEBUG, $bubble = true)
    {
        if (!function_exists('json_encode')) {
            throw new \RuntimeException('PHP\'s json extension is required to use Monolog\'s LokiHandler');
        }
        parent::__construct($level, $bubble);
        $this->entrypoint = $this->getEntrypoint($apiConfig['entrypoint']);
        $this->globalContext = $apiConfig['context'] ?? [];
        $this->globalLabels = $apiConfig['labels'] ?? [];
        $this->systemName = $apiConfig['client_name'] ?? null;
        if (isset($apiConfig['auth']['basic'])) {
            $this->basicAuth = (2 === count($apiConfig['auth']['basic'])) ? $apiConfig['auth']['basic'] : [];
        }
    }

    private function getEntrypoint(string $entrypoint): string
    {
        if ('/' !== substr($entrypoint, -1)) {
            return $entrypoint;
        }

        return substr($entrypoint, 0, -1);
    }

    /** @throws \JsonException */
    public function handleBatch(array $records): void
    {
        $rows = [];
        foreach ($records as $record) {
            if (!$this->isHandling($record)) {
                continue;
            }

            $record = $this->processRecord($record);
            $rows[] = $this->getFormatter()->format($record);
        }

        $this->sendPacket(['streams' => $rows]);
    }

    /** @throws \JsonException */
    private function sendPacket(array $packet): void
    {
        $payload = json_encode($packet, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $url = sprintf('%s/loki/api/v1/push', $this->entrypoint);
        $ch = curl_init($url);
        if (!empty($this->basicAuth)) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, implode(':', $this->basicAuth));
        }
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload),
            ]
        );

        Curl\Util::execute($ch);
    }

    protected function getDefaultFormatter(): FormatterInterface
    {
        return new LokiFormatter($this->globalLabels, $this->globalContext, $this->systemName);
    }

    /** @throws \JsonException */
    protected function write(array $record): void
    {
        $this->sendPacket(['streams' => [$record['formatted']]]);
    }
}
