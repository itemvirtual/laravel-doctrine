# Changelog

All notable changes to `laravel-doctrine` will be documented in this file

## 2.0.1 - 2026-09-25

Fix collation and foreign key column order in doctrine:update  
doctrine:generate-mappings corrections
Added _skills

## 2.0.0 - 2026-09-24

Rewrite on doctrine/dbal ^4.2, drop doctrine/orm, doctrine/annotations and symfony/cache  
xml-mappings are read and compared directly, no more entity generation  
remove doctrine:generate-entities, doctrine:remove-entities, doctrine:convert-mapping, doctrine:clear-cache*  
Important: `_skills/upgrade-v2-xml.md`

## 1.0.7 - 2026-06-12

Add DB_CHARSET config

## 1.0.6 - 2023-03-14

Upgrade for laravel 10

## 1.0.5 - 2022-07-29

update doctrine/orm to 2.12.3  
add doctrine/annotations  
add symfony/cache

## 1.0.4 - 2022-07-29

Add php 8 to composer requirements

## 1.0.3 - 2021-11-15

Add default value 0 to boolean fields Generate migrations `tests/database/migrations` from an existing database.  
Intended for testing purposes

## 1.0.2 - 2021-06-29

fixed doctrine/orm to version 2.8.4

## 1.0.1 - 2021-05-17

Add namespaces to generated entities, with config parameter

### Improvements

Reformat Traits

## 1.0.0 - 2021-04-21

- initial release