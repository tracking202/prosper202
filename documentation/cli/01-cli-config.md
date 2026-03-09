# CLI Configuration Commands

Configure the CLI's connection to your Prosper202 instance. These commands do not call the API (except `config:test`).

## config:show

Show current configuration. The API key is masked (first 4 and last 4 characters shown).

```bash
./cli/prosper202 config:show
```

## config:set-url

Set the base URL of your Prosper202 instance.

```bash
./cli/prosper202 config:set-url https://your-domain.com
```

Trailing slashes are automatically stripped.

## config:set-key

Set the API key used for authentication.

```bash
./cli/prosper202 config:set-key YOUR_API_KEY
```

## config:test

Test connectivity by calling the `/system/health` endpoint.

```bash
./cli/prosper202 config:test
```

Returns success if the API is reachable and the key is valid.
