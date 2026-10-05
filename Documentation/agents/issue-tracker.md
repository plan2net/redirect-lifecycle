# Issue tracker: GitHub

Issues and specs live in GitHub Issues for `plan2net/redirect-lifecycle`.
Use the `gh` CLI from this clone.

## Conventions

- Create: `gh issue create --title "..." --body-file <file>`
- Read, including discussion: `gh issue view <number> --comments`
- List: `gh issue list --state open --json number,title,body,labels`
- Comment: `gh issue comment <number> --body-file <file>`
- Apply labels: `gh issue edit <number> --add-label "..."`
- Remove labels: `gh issue edit <number> --remove-label "..."`
- Close: `gh issue close <number> --comment "..."`

Use a UTF-8 file with actual newlines for multiline bodies.

## Pull requests as a triage surface

**PRs as a request surface: no.**

## Skill instructions

When a skill says "publish to the issue tracker", create a GitHub issue.
When a skill says "fetch the relevant ticket", read the issue and its comments.
