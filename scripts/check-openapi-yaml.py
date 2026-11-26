#!/usr/bin/env python3
"""docs/openapi.yaml parses as YAML, with no key given twice in one mapping.

Nothing read the spec as YAML: the PHP tests that pair it with the router
(tests/Api/V3/RouteInventory.php) read it line by line, so a description
written as a plain scalar containing ": " ("Required: the upsert ...") made
the whole file unparseable for every OpenAPI tool and every check stayed
green. PyYAML also keeps the last of two equal keys without a word, which in
a spec is a path or a field that silently replaces another; the loader below
refuses that.

    scripts/check-openapi-yaml.py [docs/openapi.yaml]

Every operation that takes an `offset` query parameter documents a boolean
`truncated` in its 200 answer (PagedAnswersSayWhetherRowsRemainTest holds the
controllers to answering it): a page of exactly the rows that remain reads
the same as one with rows after it unless the answer says which.

Exits 0 when the file parses, has an `openapi` version and a non-empty
`paths`, and every paged answer documents `truncated`; 1 with the reason
otherwise.
"""
import sys

import yaml


class UniqueKeyLoader(yaml.SafeLoader):
    """SafeLoader that refuses a key given twice in one mapping."""


def construct_unique_mapping(loader, node, deep=False):
    seen = {}
    for key_node, _value_node in node.value:
        key = loader.construct_object(key_node, deep=deep)
        if key in seen:
            raise yaml.constructor.ConstructorError(
                None, None,
                f'key {key!r} is given twice in one mapping (first on line {seen[key]})',
                key_node.start_mark,
            )
        seen[key] = key_node.start_mark.line + 1
    return yaml.SafeLoader.construct_mapping(loader, node, deep)


UniqueKeyLoader.add_constructor(yaml.resolver.BaseResolver.DEFAULT_MAPPING_TAG, construct_unique_mapping)


def resolve(spec, node):
    """$node with every $ref followed; None for a reference that names nothing."""
    seen = 0
    while isinstance(node, dict) and '$ref' in node:
        seen += 1
        ref = node['$ref']
        if not isinstance(ref, str) or not ref.startswith('#/') or seen > 20:
            return None
        node = spec
        for part in ref[2:].split('/'):
            if not isinstance(node, dict) or part not in node:
                return None
            node = node[part]
    return node


def schema_properties(spec, schema):
    """The properties a schema's object holds, through $ref and allOf."""
    schema = resolve(spec, schema)
    if not isinstance(schema, dict):
        return {}
    props = {}
    for part in schema.get('allOf') or []:
        props.update(schema_properties(spec, part))
    own = schema.get('properties')
    if isinstance(own, dict):
        props.update(own)
    return props


def unsaid_pages(spec):
    """Every "METHOD /path" taking `offset` whose 200 documents no boolean `truncated`."""
    out = []
    for path, item in spec['paths'].items():
        if not isinstance(item, dict):
            continue
        shared = item.get('parameters') or []
        for method, op in item.items():
            if method not in ('get', 'put', 'post', 'patch', 'delete') or not isinstance(op, dict):
                continue
            params = [resolve(spec, p) for p in (op.get('parameters') or []) + shared]
            if not any(isinstance(p, dict) and p.get('name') == 'offset' and p.get('in') == 'query' for p in params):
                continue
            responses = op.get('responses') or {}
            answer = resolve(spec, responses.get('200', responses.get(200)))
            schema = None
            if isinstance(answer, dict):
                schema = ((answer.get('content') or {}).get('application/json') or {}).get('schema')
            truncated = resolve(spec, schema_properties(spec, schema).get('truncated'))
            if not (isinstance(truncated, dict) and truncated.get('type') == 'boolean'):
                out.append(f'{method.upper()} {path}')
    return out


def main() -> int:
    path = sys.argv[1] if len(sys.argv) > 1 else 'docs/openapi.yaml'
    try:
        with open(path, encoding='utf-8') as handle:
            spec = yaml.load(handle, Loader=UniqueKeyLoader)
    except (OSError, yaml.YAMLError) as error:
        print(f'{path} is not a readable YAML document: {error}', file=sys.stderr)
        return 1
    if not isinstance(spec, dict) or not spec.get('openapi') or not isinstance(spec.get('paths'), dict) or not spec['paths']:
        print(f'{path} parses but is not an OpenAPI document: it needs an `openapi` version and a non-empty `paths`', file=sys.stderr)
        return 1
    unsaid = unsaid_pages(spec)
    if unsaid:
        print(f'{path}: these take `offset` and document no boolean `truncated` in their 200 answer '
              '(components/schemas/PageTruncated):\n  ' + '\n  '.join(unsaid), file=sys.stderr)
        return 1
    print(f'{path}: OpenAPI {spec["openapi"]}, {len(spec["paths"])} paths, no repeated keys, every paged answer documents truncated')
    return 0


if __name__ == '__main__':
    sys.exit(main())
