# A Yii2 implementation of a Slack Monolog handler.

[![codecov](https://codecov.io/gh/jc-it/yii2-monolog-slack-handler/branch/master/graph/badge.svg)](https://codecov.io/gh/jc-it/yii2-monolog-slack-handler)
[![Continous integration](https://github.com/jc-it/yii2-monolog-slack-handler/actions/workflows/ci.yaml/badge.svg)](https://github.com/jc-it/yii2-monolog-slack-handler/actions/workflows/ci.yaml)
![Packagist Total Downloads](https://img.shields.io/packagist/dt/jc-it/yii2-monolog-slack-handler)
![Packagist Monthly Downloads](https://img.shields.io/packagist/dm/jc-it/yii2-monolog-slack-handler)
![GitHub tag (latest by date)](https://img.shields.io/github/v/tag/jc-it/yii2-monolog-slack-handler)
![Packagist Version](https://img.shields.io/packagist/v/jc-it/yii2-monolog-slack-handler)

This extension provides a package that implements some traits and behaviors to work with model attribute translations.

```bash
$ composer require jc-it/yii2-monolog-slack-handler
```

or add

```
"jc-it/yii2-monolog-slack-handler": "^<latest version>"
```

to the `require` section of your `composer.json` file.

## Configuration

For basic configuration see https://github.com/samdark/yii2-psr-log-target.

```php
$logger = new Logger('slack-logger');
$handler = new \JCIT\components\log\SlackErrorWebhookHandler(
    webhookUrl: '<slackErrorWebhookHandler>',
    channel: '<slackChannel>',
    username: '<displayUsername>',
    level: \Monolog\Logger::WARNING,
);
$logger->pushHandler($handler);
```

## TODO
- Fix PHPStan, re-add to `captainhook.json`
    - ```      
      {
          "action": "vendor/bin/phpstan",
          "options": [],
          "conditions": []
      },
      ```
- Add tests

## Credits
- [Joey Claessen](https://github.com/joester89)
- [Sergey Makinen](https://github.com/sergeymakinen) for the abandoned [yii2-slack-log package](https://packagist.org/packages/sergeymakinen/yii2-slack-log)

## License

The MIT License (MIT). Please see [LICENSE](https://github.com/jc-it/yii2-monolog-slack-handler/blob/master/LICENSE) for more information.
