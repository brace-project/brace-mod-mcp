"""Black-box compatibility test against the official MCP Python SDK v2."""

import asyncio
import json

from mcp import Client
from mcp.types import PromptReference


async def main() -> None:
    async with Client("http://127.0.0.1:8099/mcp") as client:
        assert client.protocol_version == "2026-07-28"
        assert client.server_info.name == "crm-example"
        tools = await client.list_tools()
        assert [tool.name for tool in tools.tools] == ["get_customer"]
        result = await client.call_tool("get_customer", {"customerId": 4711})
        assert not result.is_error
        assert result.structured_content == {"id": 4711, "name": "Muster GmbH"}
        invalid = await client.call_tool("get_customer", {"customerId": "bad"})
        assert invalid.is_error
        prompts = await client.list_prompts()
        assert prompts.prompts[0].name == "customer_summary"
        prompt = await client.get_prompt("customer_summary", {"customerId": "4711"})
        assert prompt.messages[0].role == "user"
        resources = await client.list_resources()
        assert str(resources.resources[0].uri) == "config://application"
        templates = await client.list_resource_templates()
        assert templates.resource_templates[0].uri_template == "customer://{customerId}"
        resource = await client.read_resource("customer://4711")
        assert json.loads(resource.contents[0].text)["id"] == "4711"
        completion = await client.complete(
            ref=PromptReference(type="ref/prompt", name="customer_summary"),
            argument={"name": "customerId", "value": "471"},
        )
        assert completion.completion.values == ["4711", "4712"]
    print("Official MCP Python SDK v2: all HTTP compatibility checks passed.")


if __name__ == "__main__":
    asyncio.run(main())
