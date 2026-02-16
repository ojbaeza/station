## Description

Brief description of the changes in this PR.

**Related Issue:** Fixes #(issue number)

## Type of Change

- [ ] Bug fix (non-breaking change that fixes an issue)
- [ ] New feature (non-breaking change that adds functionality)
- [ ] Breaking change (fix or feature that would cause existing functionality to change)
- [ ] Documentation update
- [ ] Refactoring (no functional changes)
- [ ] Test improvements

## Changes Made

- Change 1
- Change 2
- Change 3

## Testing

Describe the tests you ran to verify your changes:

```bash
# Commands used to test
docker exec station_php bash -c "XDEBUG_MODE=off php artisan test --filter YourTest"
```

**Test coverage:** X% (minimum 95% required)

## Checklist

### Code Quality
- [ ] My code follows the project's code style (PER-CS 3.0)
- [ ] I have run `composer cs-fix` to fix code style issues
- [ ] I have run PHPStan and fixed any issues
- [ ] My changes generate no new warnings

### Testing
- [ ] I have added tests that prove my fix/feature works
- [ ] New and existing unit tests pass locally
- [ ] Test coverage meets the 95% minimum requirement

### Documentation
- [ ] I have updated the documentation (if applicable)
- [ ] I have updated CHANGELOG.md (for user-facing changes)
- [ ] My changes don't require documentation updates

### Compatibility
- [ ] My changes are backward compatible
- [ ] I have noted any breaking changes above

## Screenshots (if applicable)

Add screenshots for UI changes.

## Additional Notes

Any additional information reviewers should know.
