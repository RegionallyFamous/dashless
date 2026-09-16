# Separate implementation tasks

Open two new tasks in `/Users/nick/Documents/ChatGPT/Dashless`. Paste the corresponding prompt below. Each file is a self-contained implementation brief with scope, source paths, boundaries and acceptance tests.

## WordPress plugin prompt

> Implement the Dashless hosted WordPress site plugin. Read `/Users/nick/Documents/ChatGPT/Dashless/hosted/handoffs/wordpress-plugin.md` and follow it as the implementation brief. Preserve the existing local workflow, implement and test the assigned work, keep live checkout disabled, and report actual results and blockers. Other tasks may edit this repository; follow the ownership boundaries and do not revert their changes.

## ChatGPT app prompt

> Implement the Dashless ChatGPT app and its PHP MCP integration. Read `/Users/nick/Documents/ChatGPT/Dashless/hosted/handoffs/chatgpt-app.md` and follow it as the implementation brief. Use the existing Hub and versioned site contract, preserve the local Codex tools, keep live checkout disabled, and report actual results and blockers. Other tasks may edit this repository; follow the ownership boundaries and do not revert their changes.

The tasks can develop independently using contract fixtures. Full integration requires the WordPress site plugin first, then both workstreams running together. Neither fixture success nor successful task dispatch proves production readiness. Do not silently change a shared contract; document a proposed additive change and update both handoffs when it is accepted.
