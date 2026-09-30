#!/usr/bin/env python3
"""Fail when dexed code references a platform API newer than its minSdk.

    dex-api-check.py <api-versions.xml> <min-api> <classes.dex>...

Animal Sniffer checks :core's class files against the API-21 signature, but
its ignores are per class, and it ignores java.lang.Boolean, Long and Double
whole because D8 backports the Java 8 static helpers Kotlin emits on them
(Long.hashCode(long) and the like). That trust is this check: :core is
dexed with D8 at --min-api 21, which rewrites every call it backports, and
every method, field and class the dex still references is looked up in the
platform's api-versions.xml (the table Android lint's NewApi check reads).
Anything introduced after <min-api> fails the build, so a call D8 does not
backport - on those three classes or any other - cannot hide behind the
ignore.

References to classes the table does not list (the Kotlin standard library,
the SDK's own classes) are not platform APIs and are skipped. A platform
member the table does not list anywhere in the class's hierarchy is an
error too: an answer the check cannot give is not a pass.
"""
import struct
import sys
import xml.etree.ElementTree as ET


def uleb128(data, pos):
    result = shift = 0
    while True:
        b = data[pos]
        pos += 1
        result |= (b & 0x7F) << shift
        if b < 0x80:
            return result, pos
        shift += 7


def read_dex(path):
    data = open(path, "rb").read()
    if data[:4] != b"dex\n":
        sys.exit(f"{path}: not a dex file")
    (string_ids_size, string_ids_off, type_ids_size, type_ids_off,
     proto_ids_size, proto_ids_off, field_ids_size, field_ids_off,
     method_ids_size, method_ids_off) = struct.unpack_from("<10I", data, 0x38)

    def string(i):
        off = struct.unpack_from("<I", data, string_ids_off + 4 * i)[0]
        _, pos = uleb128(data, off)  # utf16 length
        end = data.index(b"\0", pos)
        return data[pos:end].decode("utf-8", "surrogatepass")

    types = [string(struct.unpack_from("<I", data, type_ids_off + 4 * i)[0]) for i in range(type_ids_size)]

    def proto(i):
        _, ret, params_off = struct.unpack_from("<3I", data, proto_ids_off + 12 * i)
        params = ""
        if params_off:
            n = struct.unpack_from("<I", data, params_off)[0]
            params = "".join(types[struct.unpack_from("<H", data, params_off + 4 + 2 * k)[0]] for k in range(n))
        return f"({params}){types[ret]}"

    methods = []
    for i in range(method_ids_size):
        cls, pro, name = struct.unpack_from("<HHI", data, method_ids_off + 8 * i)
        methods.append((types[cls], string(name) + proto(pro)))
    fields = []
    for i in range(field_ids_size):
        cls, _, name = struct.unpack_from("<HHI", data, field_ids_off + 8 * i)
        fields.append((types[cls], string(name)))
    return types, methods, fields


def since_of(value, default):
    if value is None:
        return default
    # "24", or a newer table's "36.1": the major level is what minSdk compares with.
    return int(value.split(".")[0])


def load_api(path):
    classes = {}
    for c in ET.parse(path).getroot().iter("class"):
        since = since_of(c.get("since"), 1)
        members = {}
        for m in c:
            if m.tag in ("method", "field"):
                members[m.get("name")] = since_of(m.get("since"), since)
        supers = [e.get("name") for e in c if e.tag in ("extends", "implements")]
        classes[c.get("name")] = (since, members, supers)
    return classes


def lookup(classes, cls, member, seen=None):
    """The API level [member] arrived at on [cls] or a supertype; None if nowhere."""
    seen = seen or set()
    if cls in seen or cls not in classes:
        return None
    seen.add(cls)
    since, members, supers = classes[cls]
    if member in members:
        return members[member]
    found = [s for s in (lookup(classes, sup, member, seen) for sup in supers) if s is not None]
    return min(found) if found else None


def main():
    if len(sys.argv) < 4:
        sys.exit(__doc__)
    classes = load_api(sys.argv[1])
    min_api = int(sys.argv[2])
    problems = []
    checked = 0
    for dex in sys.argv[3:]:
        types, methods, fields = read_dex(dex)
        for t in types:
            name = t.lstrip("[")
            if name.startswith("L") and name[1:-1] in classes:
                checked += 1
                since = classes[name[1:-1]][0]
                if since > min_api:
                    problems.append(f"class {name[1:-1]} (API {since})")
        for kind, refs in (("method", methods), ("field", fields)):
            for cls, member in refs:
                internal = cls[1:-1] if cls.startswith("L") else None
                if internal is None or internal not in classes:
                    continue
                checked += 1
                since = lookup(classes, internal, member)
                if since is None:
                    problems.append(f"{kind} {internal}.{member} is not in the API table")
                elif since > min_api:
                    problems.append(f"{kind} {internal}.{member} (API {since})")
    print(f"{checked} platform references checked against API {min_api}")
    for p in sorted(set(problems)):
        print(f"error: {p}", file=sys.stderr)
    if problems:
        sys.exit(1)
    if checked == 0:
        sys.exit("error: no platform references were found; the dex was not read")


if __name__ == "__main__":
    main()
