The fake Claude CLI comes from particle-academy/prism-acp, source commit
`06149dea5105ff0287fa99b3e79d087ad37cf846`, under the MIT license. It runs no model calls or real tools.
It is owned by these consumer tests because the released Composer archive excludes the package's tests.
The hook matcher implements Claude's documented empty/all-tools behavior.
For alpha.5's permission-hook version gate, initialize and system/init both
advertise the same synthetic `claude_code_version` (`2.1.310`), matching the
release source's fixture at `ae62700bf719dccbb3aaa9d002c558bbe765eb74`.
This fixture is protocol evidence; it does not measure a real Claude CLI.
Exec-form hooks forward the command and its argument array directly, without
shell concatenation, so the denial control runs the real alpha.5 native adapter.

`fake-codex.php` is a consumer-owned synthetic native app-server, based on the
public protocol used by prism-acp `050d9d5f063849680497b4eac5a92f70490e1cdc`
(MIT). It supplies deterministic model/config/history/approval frames without
model calls or real tools. Delayed history and malformed configuration are
explicit refusal controls. Its dummy sensitive values test error redaction.
