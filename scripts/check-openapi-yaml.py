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

Exits 0 when the file parses and has an `openapi` version and a non-empty
`paths`; 1 with the reason otherwise.
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
    print(f'{path}: OpenAPI {spec["openapi"]}, {len(spec["paths"])} paths, no repeated keys')
    return 0


if __name__ == '__main__':
    sys.exit(main())
