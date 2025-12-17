# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Initial release of the Application Logger Symfony Bundle
- `ExceptionSubscriber` for automatic exception capture
- `ApplicationLoggerHandler` for Monolog integration
- `BreadcrumbCollector` for tracking user actions leading up to errors
- `ContextCollector` for gathering request, server, and user context
- `CircuitBreaker` for resilience against API failures
- `ApiClient` for sending errors to the Application Logger platform
- JavaScript SDK for frontend error tracking
  - Automatic error capture for uncaught exceptions
  - Console breadcrumb tracking
  - Network request breadcrumb tracking
  - Session replay support
  - Rate limiting and deduplication
  - Web Crypto API SHA-256 session hashing (GDPR-compliant)
- Twig templates for SDK initialization
- Full test coverage for PHP and JavaScript components

### Security
- IP address anonymization (masks last octet for IPv4, last 80 bits for IPv6)
- Sensitive data scrubbing (password, token, api_key, secret, authorization)
- Session hashing using SHA-256 for privacy-preserving session tracking
- Circuit breaker prevents cascade failures

## [1.0.0] - TBD

### Added
- First stable release

[Unreleased]: https://github.com/dennisvanbeersel/application-logger-bundle/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/dennisvanbeersel/application-logger-bundle/releases/tag/v1.0.0
