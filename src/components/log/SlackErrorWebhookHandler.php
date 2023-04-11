<?php

declare(strict_types=1);

namespace JCIT\components\log;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\Curl\Util;
use Monolog\Handler\MissingExtensionException;
use Monolog\Logger;
use Monolog\Utils;
use Throwable;
use yii\base\Application;
use yii\base\InvalidConfigException;
use yii\console\Request as ConsoleRequest;
use yii\helpers\ArrayHelper;
use yii\helpers\VarDumper;
use yii\web\Request as WebRequest;

/**
 * Very ugly code, based on sergeymakinen\yii\slacklog
 */
class SlackErrorWebhookHandler extends AbstractProcessingHandler
{
    private bool $_isConsoleRequest;
    public array $colors = [
        Logger::ERROR => 'danger',
        Logger::WARNING => 'warning',
    ];
    public array $logVars = [
        '_GET',
        '_POST',
        '_FILES',
        '_COOKIE',
        '_SESSION',
        '_SERVER',
    ];
    public array $maskVars = [
        '_SERVER.HTTP_AUTHORIZATION',
        '_SERVER.PHP_AUTH_USER',
        '_SERVER.PHP_AUTH_PW',
    ];

    public function __construct(
        protected string $webhookUrl,
        protected ?string $channel = null,
        protected ?string $username = null,
        protected ?string $iconEmoji = null,
        $level = Logger::DEBUG,
        bool $bubble = true
    ) {
        if (!extension_loaded('curl')) {
            throw new MissingExtensionException('The curl extension is needed to use the SlackWebhookHandler');
        }

        parent::__construct($level, $bubble);
    }

    protected function app(): ?Application
    {
        return \Yii::$app;
    }

    protected function encode($string): string
    {
        return htmlspecialchars($string, ENT_NOQUOTES, 'UTF-8');
    }

    public function formatMessage($record): string
    {
        $text = $record['message'];
        $level = $record['level_name'];
        $category = $record['context']['category'];
        $timestamp = $record['datetime']->getTimestamp();

        if (!is_string($text)) {
            // exceptions may not be serializable if in the call stack somewhere is a Closure
            if ($text instanceof Throwable) {
                $text = (string) $text;
            } else {
                $text = VarDumper::export($text);
            }
        }
        $traces = [];
        if (isset($record['context']['exception'])) {
            foreach (array_slice($record['context']['exception']->getTrace(), 1) as $trace) {
                $traces[] = "in {$trace['file']}:{$trace['line']}";
            }
        }

        $prefix = $this->getMessagePrefix($record);
        return $this->getTime($timestamp) . " {$prefix}[$level][$category] $text"
            . (empty($traces) ? '' : "\n    " . implode("\n    ", $traces));
    }

    protected function formatRecordAttachment(array $record): array
    {
        $attachment = [
            'fallback' => $this->encode($this->formatMessage($record)),
            'title' => ucwords($record['level_name']),
            'fields' => [],
            'text' => "```\n" . $this->encode($record['message']) . "\n```",
            'footer' => $this->app()->id,
            'ts' => (int) round($record['datetime']->getTimestamp()),
            'mrkdwn_in' => [
                'fields',
                'text',
                'Global variables',
            ],
        ];
        if ($this->getIsConsoleRequest()) {
            $attachment['author_name'] = $this->getCommandLine();
        } else {
            $attachment['author_name'] = $attachment['author_link'] = $this->getUrl();
        }
        if (isset($this->colors[$record['level']])) {
            $attachment['color'] = $this->colors[$record['level']];
        }
        $this
            ->insertField($attachment, 'Level', $record['level_name'], true, false)
            ->insertField($attachment, 'Category', $record['context']['category'], true)
            ->insertField($attachment, 'Prefix', $this->getMessagePrefix($record), true)
            ->insertField($attachment, 'User IP', $this->getUserIp(), true, false)
            ->insertField($attachment, 'User ID', $this->getUserId(), true, false)
            ->insertField($attachment, 'Session ID', $this->getSessionId(), true, false)
            ->insertField($attachment, 'Stack Trace', $this->getStackTrace($record), false)
            ->insertField($attachment, 'Global variables', $this->getGlobalVariables(), false);
        return $attachment;
    }

    public function getCommandLine(): ?string
    {
        if ($this->app() === null || !$this->getIsConsoleRequest()) {
            return null;
        }

        $params = [];
        if (isset($_SERVER['argv'])) {
            $params = $_SERVER['argv'];
        }
        return implode(' ', $params);
    }

    public function getGlobalVariables(): string
    {
        $context = ArrayHelper::filter($GLOBALS, $this->logVars);
        foreach ($this->maskVars as $var) {
            if (ArrayHelper::getValue($context, $var) !== null) {
                ArrayHelper::setValue($context, $var, '***');
            }
        }
        $result = [];
        foreach ($context as $key => $value) {
            $result[] = "\${$key} = " . VarDumper::dumpAsString($value);
        }

        return implode("\n\n", $result);
    }

    public function getIsConsoleRequest(): bool
    {
        if (!isset($this->_isConsoleRequest) && $this->app() !== null) {
            if ($this->app()->getRequest() instanceof ConsoleRequest) {
                $this->_isConsoleRequest = true;
            } elseif ($this->app()->getRequest() instanceof WebRequest) {
                $this->_isConsoleRequest = false;
            }
        }
        if (!isset($this->_isConsoleRequest)) {
            throw new InvalidConfigException('Unable to determine if the application is a console or web application.');
        }

        return $this->_isConsoleRequest;
    }

    public function getMessagePrefix(array $record): string
    {
        if ($this->app() === null) {
            return '';
        }

        $request = $this->app()->getRequest();
        $ip = $request instanceof WebRequest ? $request->getUserIP() : '-';

        /* @var $user \yii\web\User */
        $user = $this->app()->has('user', true) ? $this->app()->get('user') : null;
        if ($user && ($identity = $user->getIdentity(false))) {
            $userID = $identity->getId();
        } else {
            $userID = '-';
        }

        /* @var $session \yii\web\Session */
        $session = $this->app()->has('session', true) ? $this->app()->get('session') : null;
        $sessionID = $session && $session->getIsActive() ? $session->getId() : '-';

        return "[$ip][$userID][$sessionID]";
    }

    protected function getPayload(array $record): array
    {
        $payload = [
            'parse' => 'none',
            'attachments' => [$this->formatRecordAttachment($record)],
        ];
        $this
            ->insertIntoPayload($payload, 'username', $this->username)
            ->insertIntoPayload($payload, 'icon_emoji', $this->iconEmoji)
            ->insertIntoPayload($payload, 'channel', $this->channel);

        return $payload;
    }

    public function getSessionId(): ?string
    {
        if (
            $this->app() !== null
            && $this->app()->has('session', true)
            && $this->app()->getSession() !== null
            && $this->app()->getSession()->getIsActive()
        ) {
            return $this->app()->getSession()->getId();
        } else {
            return null;
        }
    }

    public function getStackTrace(array $record): ?string
    {
        if (!isset($record['context']['exception'])) {
            return null;
        }

        $traces = array_map(function ($trace) {
            return "in {$trace['file']}:{$trace['line']}";
        }, array_slice($record['context']['exception']->getTrace(), 1));
        return implode("\n", $traces);
    }

    protected function getTime($timestamp): string
    {
        $parts = explode('.', sprintf('%F', $timestamp));

        return date('Y-m-d H:i:s', intval($parts[0]));
    }

    public function getUrl(): ?string
    {
        if ($this->app() === null || $this->getIsConsoleRequest()) {
            return null;
        }

        return $this->app()->getRequest()->getAbsoluteUrl();
    }

    public function getUserId(): int|string|null
    {
        if (
            $this->app() !== null
            && $this->app()->has('user', true)
            && $this->app()->getUser() !== null
        ) {
            $user = $this->app()->getUser()->getIdentity(false);
            if ($user !== null) {
                return $user->getId();
            }
        }
        return null;
    }

    public function getUserIp(): ?string
    {
        if ($this->app() === null || $this->getIsConsoleRequest()) {
            return null;
        }

        return $this->app()->getRequest()->getUserIP();
    }

    private function insertField(array &$attachment, $title, $value, $short, $wrapAsCode = true): self
    {
        if ((string) $value === '') {
            return $this;
        }

        $value = $this->encode($value);
        if ($wrapAsCode) {
            if ($short) {
                $value = '`' . $value . '`';
            } else {
                $value = "```\n" . $value . "\n```";
            }
        }
        $attachment['fields'][] = [
            'title' => $title,
            'value' => $value,
            'short' => $short,
        ];
        return $this;
    }

    private function insertIntoPayload(array &$payload, $name, $value): self
    {
        if ((string) $value !== '') {
            $payload[$name] = $value;
        }
        return $this;
    }

    protected function write(array $record): void
    {
        $postData = $this->getPayload($record);
        $postString = Utils::jsonEncode($postData);

        $ch = curl_init();
        $options = [
            CURLOPT_URL => $this->webhookUrl,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-type: application/json'],
            CURLOPT_POSTFIELDS => $postString,
        ];
        if (defined('CURLOPT_SAFE_UPLOAD')) {
            $options[CURLOPT_SAFE_UPLOAD] = true;
        }

        curl_setopt_array($ch, $options);

        Util::execute($ch);
    }
}
