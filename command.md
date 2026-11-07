# Operator command navigation

This legacy entry point is retained for existing links. Use the maintained
[configuration/CLI reference](docs/reference/configuration.md) and
[usage guide](docs/guides/USAGE_GUIDE.md).

```bash
php scripts/create_api_key.php --name=example-client --ips=127.0.0.1
CD_BASE_URL=http://127.0.0.1:8080 CD_API_KEY='<YOUR_TEST_API_KEY>' php scripts/test_enqueue.php
```

The key-creation command prints a newly generated raw key once. Store that value
privately; do not paste it into documentation, issues or committed shell files.
The diagnostic call is real v1 enqueue; point it only at an explicitly chosen test
service with nonsensitive data. It is not a no-send or atomic-recovery example.
