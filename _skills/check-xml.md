---
name: check-xml
description: Verify that Doctrine XML mappings (*.dcm.xml) correctly represent every real foreign key as a <many-to-one> or <many-to-many> relation, not a plain field, and that existing relations are structurally valid.
when_to_use: After doctrine:generate-mappings runs, after any manual edit to the xml-mappings, or before trusting doctrine:update's diff on a relation-heavy entity.
mutates_files: false
requires: A working `php artisan` in this project, and read access to its database.
---

# Check XML mappings

Target directory: `xml_mappings_path` in `config/laravel-doctrine.php` (defaults to
`database/doctrine/xml-mappings`, but check the config first — it may have been changed). All `*.dcm.xml` files
in it.

Goal: catch relations that are missing or wrong. The main failure mode this checks for: a column that is a
real foreign key in the database, but shows up in the XML as a plain `<field>` instead of being part of a
`<many-to-one>` or `<many-to-many>` — this has happened before (`doctrine:generate-mappings` used to emit
`course_id` as a plain `bigint` field instead of a relation).

**Do not modify any file.** This is a read-only investigation. Never apply a fix without the user's explicit
authorization — present findings first (see Report below) and wait for the user to say which ones to act on.

## 1. Every real foreign key must be a relation, not a field

This is the main thing to check, and it must be checked directly against the files — a freshly generated XML
will always match the database on column-level facts (type, length, options), so the `doctrine:update`
cross-check at the end of this document proves nothing about this specific point on its own.

For each table, list its real foreign keys (`information_schema.KEY_COLUMN_USAGE` /
`information_schema.REFERENTIAL_CONSTRAINTS`, or `SHOW CREATE TABLE`). For each one, open that entity's XML
and confirm its column is NOT a plain `<field>` — it must instead be the join column of a `<many-to-one>`, or,
if the table is a pure join table (composite primary key made entirely of foreign keys, no other columns),
part of a `<many-to-many>`'s `<join-table>` on the related entity. List every foreign key column found as a
plain `<field>` — that is the defect this whole check exists to catch.

## 2. `<many-to-one>` relations

For every `<many-to-one>`:

- `target-entity` must match the `name` attribute of an `<entity>` that actually exists in one of the
  `.dcm.xml` files in this directory. A typo or a renamed entity here fails at runtime with "unknown
  target-entity", not silently.
- Each `<join-column>`'s `referenced-column-name` must match the target entity's `<id>` column name exactly.
- The `field` name must not collide with an existing `<field>`, or with another `<many-to-one>`/`<many-to-many>`
  field, on the same entity.
- Confirm there is a real foreign key in the database between the two tables, with matching columns and
  `ON DELETE` behavior — check `information_schema.KEY_COLUMN_USAGE` /
  `information_schema.REFERENTIAL_CONSTRAINTS`, or the table's actual `SHOW CREATE TABLE`.

## 3. `<many-to-many>` relations

For every `<many-to-many>`:

- `target-entity` must exist, same as above.
- The `<join-table>` name must match a real table in the database.
- Every `referenced-column-name`, in both `<join-columns>` and `<inverse-join-columns>`, must match the
  corresponding side's actual `<id>` column.
- The join table itself must NOT also exist as its own `<entity>` in a separate `.dcm.xml` file. A pure
  many-to-many join table — composite primary key made entirely of foreign keys, no other columns — should
  only ever be represented here, never as a standalone entity (this package only reads a single `<id>` per
  entity, so a second one is silently dropped instead of raising an error).
- If the relation is bidirectional, the inverse side must use `mapped-by` and must NOT declare its own
  `<join-table>` — only one side may own it.

## 4. General consistency

- Every `<entity>` has exactly one `<id>`.
- Every `<entity name="X">` is declared only once across the whole directory.
- No leftover `fetch="LAZY"` (or any other `fetch` value) on `<many-to-one>` — see `_skills/upgrade-v2-xml.md`.
- No leftover `<option name="fixed"/>` unless a fixed-length CHAR column is genuinely intended — see
  `_skills/upgrade-v2-xml.md`.

## 5. Cross-check (secondary, not a substitute for step 1)

Run:

```bash
php artisan doctrine:update --dump-sql
```

This only compares column-level facts (type, length, options, indexes) between the XML and the real schema —
it does NOT know whether a column should have been a relation. "Nothing to update" here does not mean step 1
is satisfied; it only confirms there's no other mismatch. Any statement it does propose is still worth
investigating (wrong type, length, option, `on-delete`, a `DROP FOREIGN KEY` you didn't already catch in step
1, etc.).

## Report

Present every issue found as a table, one row each — nothing else, no changes applied yet:

| File | Line | Issue | Suggested fix |
|------|------|-------|---------------|

Then stop and wait. The user decides which rows to act on. Apply only the changes they explicitly approve —
nothing more. After applying them, re-run `php artisan doctrine:update --dump-sql` and confirm it reports
"Nothing to update".
