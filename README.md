# Property Research Agent

A tiny AI agent for a real estate platform. Click a house on the map of Westchester, Los Angeles,
ask the agent about it, and it researches the property, its comparable sales and its owner.

**All data is fake except the addresses.** The addresses, parcel numbers, map positions and build
years come from OpenStreetMap (© OpenStreetMap contributors, ODbL). Values, sales, mortgages,
owners, phone numbers and emails are made up by `seed.php`.

## An agent is a loop

There is no magic inside. The agent is one loop in `agent.php`:

1. Send the conversation to Claude, together with a list of tools Claude may use.
2. If Claude answers with words, show them. Done.
3. If Claude answers "please run tool X with these inputs", run that PHP function here,
   add its result to the conversation, and go back to step 1.

Claude never touches the database. It can only ask; our code decides what to run and what to answer.

## Where everything is

| File | What it is |
|---|---|
| `SYSTEM_PROMPT.txt` | Plain English instructions: who the agent is, how to use its tools, what it must refuse. |
| `tools.php` | The four tools. Each one is a description that Claude reads plus a PHP function that runs. |
| `agent.php` | The loop above, the one HTTP request to Claude, and the price calculation. |
| `server.php` | The website: login, chat list, and sending a message (streams each step to the browser). |
| `Caddyfile` | Web server settings: every request goes to `server.php`, so no other file can be downloaded. |
| `frontend/` | The pages the browser shows: login, the map and the chat. |
| `db.php`, `seed.php` | The four SQLite files in `data/` and the script that fills the house and owner data. |
| `deploy/`, `demo/reset-users.php` | The service that keeps the site running on ox-dev, and a script that deletes demo users. |
| `test.php` | Every test: tools, real conversations with Claude, and the website over HTTP. |
| `demo/record.mjs` | Plays the demo in a browser, checks each step, and can record a video. |

The four tools:

- `SearchProperty(address)` — address to property ID.
- `PropertyDetail(id)` — size, year built, last sale, value, mortgages, equity, foreclosure status.
- `PropertyComps(lat, lon, radius_meters)` — nearby houses that have a value (comparable sales).
- `OwnerDetail(id)` — owner names, type, mailing address, years owned, age range, phones, email.

The model is Claude Haiku 5.5. The system prompt and tools are cached by Anthropic, and so is each
conversation, so every new message only pays full price for what is new. A whole demo chat costs
a fraction of a cent.

## Run it

You need the FrankenPHP binary (PHP 8.5 with a web server built in) and an Anthropic API key.

```sh
echo 'ANTHROPIC_API_KEY=sk-ant-...' > .env && chmod 600 .env
frankenphp php-cli seed.php                       # build data/houses.db and data/owners.db
set -a; . ./.env; set +a; frankenphp run --config Caddyfile   # open http://127.0.0.1:8080 in Chrome
frankenphp php-cli test.php                       # all tests (or: test.php tools | agent | server)
```

## Deploy (ox-dev)

The site runs as a systemd service from `/home/dev/apps/real-estate-agent-demo`, and Tailscale Funnel
publishes it at https://ox-dev.tail4914b3.ts.net:8443.

```sh
cd /home/dev/apps/real-estate-agent-demo && git pull
sudo cp deploy/real-estate-agent.service /etc/systemd/system/ && sudo systemctl daemon-reload
sudo systemctl stop real-estate-agent        # seed.php replaces database files the server holds open
/home/dev/bin/frankenphp php-cli seed.php
sudo systemctl start real-estate-agent
```

**Update PHP:** download the newest `frankenphp-linux-x86_64` from
https://github.com/php/frankenphp/releases, replace `/home/dev/bin/frankenphp`, `chmod +x` it, restart the service.

**Reset demo users** (before recording the demo again, and after each check run):

```sh
cd /home/dev/apps/real-estate-agent-demo
/home/dev/bin/frankenphp php-cli demo/reset-users.php 'linht'
/home/dev/bin/frankenphp php-cli demo/reset-users.php 'check-%'
```

## Record the demo video

On a Mac with Node.js and ffmpeg:

```sh
cd demo && npm install && npx playwright install chromium-headless-shell
node record.mjs https://ox-dev.tail4914b3.ts.net:8443 check-$(date +%s)    # checks every step, no video
node record.mjs https://ox-dev.tail4914b3.ts.net:8443 linht --record       # same steps, saves demo.webm
ffmpeg -i demo.webm -c:v libx264 -crf 16 -preset slow -pix_fmt yuv420p -movflags +faststart demo.mp4
```
