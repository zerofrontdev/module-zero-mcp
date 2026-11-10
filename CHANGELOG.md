# Changelog: zerofront/module-zero-mcp

## 1.3.9

- New in the free set (it was a licensed module before): the store as a connector in Claude (an MCP server with OAuth). The AI Assistant's tools, commands and docs work from the Claude app on a phone or computer with the admin's own Claude subscription; every change is proposed and confirmed, photos come from the phone.
- Needs `zerofront/module-zero-ai-assistant`, which Composer installs with it. Nothing is reachable until an admin user connects Claude and approves it.
- Registrations, connections and rate limits are kept in the store's own tables; the README describes the security model.

Earlier releases (1.3.5 and before): see the Git tags.
