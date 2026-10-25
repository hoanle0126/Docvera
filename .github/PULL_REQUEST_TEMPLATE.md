## Summary
Explain what this pull request changes and why.

## Linked Issue
Closes #

## Verification Checklist
- [ ] All PHP files pass syntax lint (`composer lint`)
- [ ] `php bin/capabilities` accurately reports capability and tool readiness
- [ ] `php bin/test` passes 100% of existing and newly added tests
- [ ] Any new or modified document processing tool verifies physical output structure (not merely HTTP 200)
- [ ] Subprocess invocations strictly use `SafeProcessRunner` array arguments (zero shell interpolation)
- [ ] Temporary files are created inside `storage/app/jobs/job_<id>` (`0700`) and cleaned up in `finally` blocks
- [ ] Documentation (`README.md`, `docs/ARCHITECTURE.md`, `docs/DOCUMENTATION.md`) updated if behavior or endpoints changed

