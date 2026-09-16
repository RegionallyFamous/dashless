# Submission gate

Current cross-component launch decision and Railway acceptance gates: [September 16 launch audit](../../../docs/launch-audit-2026-09-16.md). Earlier native-build findings below are historical; no paid-launch gate has been waived.

**Not ready for public submission. Not submitted or published.**

Prepared: listing copy, selected brand assets, component screenshots, 7 positive/7 negative scenarios, developer connection guide, reviewer account procedure, pinned component/Hub packaging, OAuth/protocol/browser/fixture verification.

Still required:

- Install the packaged Hub on the actual WP Cloud HTTPS origin and confirm public discovery/tool scan, allowed OAuth client/redirect and domain ownership challenge.
- Finish and integrate the separate site runtime; verify capability flags against actual behavior, private HTML/assets, native builds, real immutable approval/replay, rollback and private exports.
- Run two isolated real subscribers through the entire journey on WP Cloud and ChatGPT. Test real file APIs and record the exact allowlisted download origin without storing signed URLs. The local bridge and service mocks are not substitute evidence.
- Demonstrate the required two-worker full-build responsiveness, process-tree memory and recovery gates. Keep checkout disabled until all existing launch gates pass.
- Validate the dedicated widget origin/CSP and browser approval UX in actual ChatGPT, including Safari/mobile cookie behavior and conversation attachment transfer. Obtain real ChatGPT screenshots.
- Prepare and verify working isolated reviewer credentials without MFA/email/SMS blocking, deliver privately in the portal.
- Finalize the public privacy/support/terms pages, legal publisher identity, retention/contact information, available regions, and production brand masters.
- Verify the publishing organization/identity and Apps Management write permission. Address any current submission requirements, including enterprise domain restrictions/OIDC UserInfo if required: the current Hub implements OAuth scopes only and does not claim OIDC email/UserInfo support.
- Complete OpenAI domain verification, Scan Tools, review and attestations. Submit only with user authorization and the configured publishing account. Approval is followed by a separate Publish step; neither has been performed.

The proposed public links in listing.md remain release-draft destinations until independently verified. No gate is marked passed by this workstream.
