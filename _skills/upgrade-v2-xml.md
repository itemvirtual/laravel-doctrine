---
name: upgrade-v2-xml
description: Clean up Doctrine XML mappings (*.dcm.xml) left over from laravel-doctrine v1's old entity-generation round-trip — stale `fixed` options, non-normalized `unsigned` options, and inert `fetch` attributes.
when_to_use: Once, right after upgrading a project from laravel-doctrine v1 to v2, before trusting doctrine:update's output.
mutates_files: true
requires: Nothing beyond read/write access to the xml-mappings directory.
---

# Upgrade v1 -> v2 XML mappings

Target directory: `xml_mappings_path` in `config/laravel-doctrine.php` (defaults to
`database/doctrine/xml-mappings`, but check the config first — it may have been changed). All `*.dcm.xml` files
in it.

Apply the following three changes to ALL entity mapping files. Do not touch anything else.

## 1. Remove the `fixed` option

Delete every `<option name="fixed"/>`.

If `fixed` is the only option inside its `<options>` block, remove the whole
`<options>` block and make the parent element self-closing.

Before:

```xml

<field name="reference" type="string" column="reference" length="255" nullable="true">
    <options>
        <option name="fixed"/>
    </options>
</field>
```

After:

```xml

<field name="reference" type="string" column="reference" length="255" nullable="true"/>
```

If `fixed` coexists with other options in the same block, remove only the
`fixed` line and keep the block with the remaining options.

## 2. Normalize the `unsigned` option

Every `unsigned` option must be exactly:

```xml

<option name="unsigned">true</option>
```

Fix empty forms (`<option name="unsigned"/>`) and value forms (`<option name="unsigned">1</option>` or any other value) to the form above.

## 3. Remove the `fetch` attribute

Delete the `fetch="LAZY"` attribute (or any other `fetch="..."` value) from every
`<many-to-one>` element. It's a Doctrine ORM runtime setting; this package only uses
doctrine/dbal for schema comparison, so it's never read and does nothing.

Before:

```xml

<many-to-one field="course" target-entity="Courses" fetch="LAZY">
```

After:

```xml

<many-to-one field="course" target-entity="Courses">
```

## Constraints

- Preserve all other options (`default`, etc.) and attributes untouched.
- Do not reformat, reorder, or reindent any unrelated line.
- Preserve the existing formatting and blank-line layout exactly.
- After finishing, report: files changed, replacements made, and confirm zero
  remaining `fixed` occurrences, zero non-`true` `unsigned` options, and zero
  remaining `fetch` attributes.
