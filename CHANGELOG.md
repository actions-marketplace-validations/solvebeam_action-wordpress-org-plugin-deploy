# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2026-10-18

### Added

- `public-url`, `svn-url` and `svn-tag-url` outputs.

## [1.0.0] - 2026-10-17

### Added

- Deploy the `{slug}.{version}.zip` asset of a GitHub release to `trunk` and `tags/{version}`.
- Check that the GitHub release exists, that the ZIP contains `readme.txt` and that the `Stable tag` matches the release version.
- Fail with a clear error when the Subversion tag already exists.
- Synchronize `trunk` with the plugin ZIP, including removed files.
- Support filenames with an `@`, such as `icon@2x.png`, without Subversion peg revision errors.
- `username`, `password`, `tag` and `slug` inputs.
- `dry-run` input to run every step except the Subversion commit and tag.
- Job summary with the result, links, Subversion changes and executed commands.
- Collapsible log groups for every step.

[unreleased]: https://github.com/solvebeam/action-wordpress-org-plugin-deploy/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/solvebeam/action-wordpress-org-plugin-deploy/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/solvebeam/action-wordpress-org-plugin-deploy/releases/tag/v1.0.0
