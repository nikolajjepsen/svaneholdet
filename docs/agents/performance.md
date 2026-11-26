# Performance

Telescope records every request in the local environment, with its queries, views, jobs and mail, in the `telescope_entries` table of the dev database. Tests don't record anything, because `phpunit.xml` turns Telescope off. To measure a page, load it on the local dev server (`composer dev`), then read the entries with Boost's `database-query` tool. A person can see the same data at `/telescope`.

## Reading the entries

Every entry has a `type` (`request`, `query`, `view`, `job`, `mail`, `exception` and more) and a JSON `content` stored as text, so cast it with `::jsonb`. Entries from the same request share a `batch_id`.

Duration and query count of the latest requests:

```sql
select r.content::jsonb->>'method' as method,
       r.content::jsonb->>'uri' as uri,
       (r.content::jsonb->>'duration')::int as ms,
       count(q.*) filter (where q.type = 'query') as queries
from telescope_entries r
left join telescope_entries q on q.batch_id = r.batch_id
where r.type = 'request'
group by r.sequence
order by r.sequence desc
limit 10;
```

## Finding an N+1

A query's `hash` is the same for every run of the same SQL shape. One hash repeated many times inside one request is an N+1:

```sql
select q.content::jsonb->>'hash' as hash,
       count(*) as runs,
       min(q.content::jsonb->>'sql') as example_sql,
       min(q.content::jsonb->>'file') || ':' || min(q.content::jsonb->>'line') as origin
from telescope_entries q
where q.type = 'query'
  and q.batch_id = '<batch_id of the request>'
group by 1
having count(*) > 1
order by runs desc;
```

Fix it with eager loading (`with()`, `load()`) at the place `origin` points to, then load the page again and compare `runs`.

## Slow queries

Telescope marks a query `"slow": true` when it takes more than 100 ms. That threshold is in `config/telescope.php`. Each query's `time` is in milliseconds. A slow query usually needs an index. Add it in a migration, and check the plan with `explain analyze`.

## Runtime errors

Boost's `last-error` tool returns the latest exception. Its `browser-logs` tool returns JavaScript errors and console output from the browser.
