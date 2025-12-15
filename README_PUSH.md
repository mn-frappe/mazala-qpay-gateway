Push instructions for `wooqpay` plugin

Goal: create the remote repository at https://github.com/dcsmn/wooqpay, set `develop` as the default branch, create `master`, and push local code.

Prerequisites (one of):
- You are authenticated to GitHub via `gh` CLI and can create repos under `dcsmn`.
- OR you have a GitHub personal access token (with `repo` scope) and can call GitHub API.
- OR you can create the repo via the GitHub web UI.

Recommended flow (fastest, using `gh`):

1) Install and authenticate `gh` (if not already):

```bash
# install gh (example for Ubuntu)
sudo apt install gh || curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg | sudo dd of=/usr/share/keyrings/githubcli-archive-keyring.gpg
sudo apt update
sudo apt install gh

# login
gh auth login
```

2) From the plugin directory (`/var/www/html/wp-content/plugins/wooqpay`):

```bash
# make script executable once
chmod +x deploy_push.sh
# run to initialize local repo and create branches
./deploy_push.sh
# then create remote and push (this requires gh authenticated as dcsmn)
gh repo create dcsmn/wooqpay --public --source=. --remote=origin --push --default-branch=develop
# ensure master exists and push
git checkout master
git push -u origin master
```

If you cannot use `gh`, use the web UI:

1) Create a new repository at https://github.com/new. Use owner `dcsmn`, name `wooqpay`.
2) After creation, GitHub will show commands to add a remote. Example:

```bash
git remote add origin git@github.com:dcsmn/wooqpay.git
git push -u origin develop
git checkout master
git push -u origin master
```

Advanced: create repo via API using `GITHUB_TOKEN` env var:

```bash
export GITHUB_TOKEN=ghp_xxx
curl -H "Authorization: token $GITHUB_TOKEN" -d '{"name":"wooqpay","private":false}' https://api.github.com/user/repos
# then add remote and push
```

After pushing:
- Visit https://github.com/dcsmn/wooqpay/actions to watch the GitHub Actions job run. The repository includes a workflow to run PHPUnit and produce coverage.
- If Actions needs access to run, enable Actions in repo settings.

Notes:
- This workspace environment could not create the remote repo directly (no permission). The above steps will let you create the remote and push from your machine.
- If you want, I can also produce a single `git bundle` or a compressed archive of the plugin to transfer to another machine. Ask and I'll prepare it.
