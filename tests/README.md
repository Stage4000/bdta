# Tests

Legacy PHP integration and workflow verification scripts live here now instead of the repository root.

- `tests/*.php`: executable test and smoke scripts for the BDTA platform
- Run from the project root so relative environment/config assumptions remain predictable
- Most scripts bootstrap the app through `dirname(__DIR__)` to keep the test tree isolated from runtime code
