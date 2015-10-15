# COOKIE-SSO

Cookieを利用した簡易的なSSOのためのサンプル

## コード規約

WordPress-Coreを利用する
https://github.com/WordPress-Coding-Standards/WordPress-Coding-Standards

### CodeSnifferのセットアップ

```bash
$ composer global require 'squizlabs/php_codesniffer=*'
$ git clone https://github.com/WordPress-Coding-Standards/WordPress-Coding-Standards.git ~/.composer/vendor/squizlabs/php_codesniffer/CodeSniffer/Standards/WordPress
$ phpcs --config-set installed_paths ~/.composer/vendor/squizlabs/php_codesniffer/CodeSniffer/Standards/WordPress
```

phpcs のPATHを.bash_profile等に通しておく必要がある

```bash
export PATH=~/.composer/vendor/bin:$PATH
```

### CodeSnifferの実行

コード規約の確認（テスト）

```bash
$ phpcs -p -s -v --standard=WordPress-Core cookie-sso.php
```

コード規約の適用（自動修正）

```bash
$ phpcbf -p -s -v --standard=WordPress-Core cookie-sso.php
```

## テスト

### テスト実行

テストは未実装

```bash
$ phpunit
```
