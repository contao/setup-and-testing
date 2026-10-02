# Contribute to the documentation

The canonical usage documentation lives in this monorepo's `docs/` directory. Package READMEs are short entry points, so changes to usage guidance belong here. Documentation follows `main` rather than maintaining separate release editions.

## Preview locally

Use an installed Python 3.8 or newer, supported by the pinned MkDocs version. Documentation CI uses Python 3.12 for consistent builds. From the monorepo root:

```shell
python3 -m venv .venv-docs
. .venv-docs/bin/activate
python -m pip install -r requirements-docs.txt
python -m mkdocs serve
```

On Windows:

```powershell
py -m venv .venv-docs
.venv-docs\Scripts\Activate.ps1
python -m pip install -r requirements-docs.txt
python -m mkdocs serve
```

If your shell does not permit activation, run `.venv-docs\Scripts\python.exe -m mkdocs serve` after installing dependencies with the same interpreter.

If you use [uv](https://docs.astral.sh/uv/getting-started/installation/), you can create the environment with `uv venv --seed .venv-docs` instead. `--seed` includes pip. Activate it and install the requirements as above.

If an existing uv environment reports `No module named pip`, install pip into it with `uv pip install --python .venv-docs/bin/python pip`. On Windows, use `.venv-docs/Scripts/python.exe` as the interpreter path.

### Address already in use

This error means another process is listening on the selected port. MkDocs uses port 8000 by default, which may already belong to your application server. Keep that server running and choose another free port:

```shell
python -m mkdocs serve --dev-addr 127.0.0.1:8766
```

If 8766 is occupied too, select another port. Stop your own MkDocs preview with Ctrl+C before starting it again.

Open the address printed by MkDocs. The configured project path means the preview is served under `/setup-and-testing/`. Search, navigation and links should behave as they will on Pages.

## Validate changes

```shell
python -m mkdocs build --strict
```

Every page belongs in the explicit navigation. Missing pages, missing anchors and unrecognized internal links are warnings, and the strict build fails on warnings. Use relative Markdown links between documentation pages.

Check examples against the current public APIs. Complete walkthroughs should specify their working directory, prerequisites, files, command and expected result. Explain existing-application state management separately from Managed Edition provisioning. Keep PHP code punctuation, but avoid semicolons in comments and prose.

The Documentation workflow runs on every pull request and on pushes to `main`, including code-only changes. Existing repository CI also checks YAML formatting and Composer manifests. The built `site/` directory and `.venv-docs/` are ignored and stay out of package splits.
