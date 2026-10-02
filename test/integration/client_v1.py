"""Black-box compatibility test against the official MCP Python SDK v1."""

import asyncio
import json

from mcp import ClientSession
from mcp.client.streamable_http import streamablehttp_client
from mcp.types import AnyUrl, PromptReference


async def main() -> None:
    async with streamablehttp_client("http://127.0.0.1:8099/mcp") as (read, write, _):
        async with ClientSession(read, write) as client:
            initialized = await client.initialize()
            assert initialized.protocolVersion in {"2025-11-25", "2025-06-18"}
            tools = await client.list_tools()
            assert [tool.name for tool in tools.tools] == ["get_customer"]
            result = await client.call_tool("get_customer", {"customerId": 4711})
            assert not result.isError
            assert result.structuredContent == {"id": 4711, "name": "Muster GmbH"}
            invalid = await client.call_tool("get_customer", {"customerId": "bad"})
            assert invalid.isError
            prompts = await client.list_prompts()
            assert prompts.prompts[0].name == "customer_summary"
            prompt = await client.get_prompt("customer_summary", {"customerId": "4711"})
            assert prompt.messages[0].role == "user"
            resources = await client.list_resources()
            assert str(resources.resources[0].uri) == "config://application"
            templates = await client.list_resource_templates()
            assert templates.resourceTemplates[0].uriTemplate == "customer://{customerId}"
            resource = await client.read_resource(AnyUrl("customer://4711"))
            assert json.loads(resource.contents[0].text)["id"] == "4711"
            completion = await client.complete(
                PromptReference(type="ref/prompt", name="customer_summary"),
                {"name": "customerId", "value": "471"},
            )
            assert completion.completion.values == ["4711", "4712"]
            await client.send_ping()
    print("Official MCP Python SDK v1: all HTTP compatibility checks passed.")


if __name__ == "__main__":
    asyncio.run(main())
