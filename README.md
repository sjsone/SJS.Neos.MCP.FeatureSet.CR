# SJS.Neos.MCP.FeatureSet.CR

MCP FeatureSet package for the Neos **Content Repository**. Provides tools for inspecting node types, browsing documents, and reading/writing content nodes.

---

## FeatureSets & Tools

### `NodeTypeFeatureSet` — prefix `node_type`

| Tool | Description |
| --- | --- |
| `node_type_list_node_types` | Lists all registered node types with their properties and constraints |
| `node_type_get_node_type` | Returns the full definition of a single node type by name |

### `DocumentFeatureSet` — prefix `document`

| Tool | Description |
| --- | --- |
| `document_list_documents` | Lists all documents of the site; filterable by node type |
| `document_add_document` | Creates a new document node at a given position |
| `document_resolve_node_path` | Resolves a human-readable URL path to a document node |

### `ContentFeatureSet` — prefix `content`

| Tool | Description |
| --- | --- |
| `content_content_tree` | Returns the full content tree of a page node |
| `content_update_content` | Updates properties on an existing content node |
| `content_add_content` | Creates a new content node inside a ContentCollection |
| `content_move_content` | Moves a content node to a different position |
| `content_remove_content` | Removes a content node |
| `content_find_nodes` | Searches nodes by type, property values, or full-text |
| `content_copy_node` | Copies a node (and optionally its children) to a new parent |
| `content_get_node_aggregate_info` | Comprehensive node aggregate info across all dimensions |
