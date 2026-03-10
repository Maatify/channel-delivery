php scripts/create_api_key.php --name=iam-core --ips=10.0.0.1,10.0.0.2

✅ API key created successfully

  Name : iam-core
  IPs  : 10.0.0.1, 10.0.0.2
  Key  : 0e786ee2e5fae08c4e4e3811215bbe8545e6c2a2026c83ed968c8c360c42c28c

⚠️  Save this key now — it will NOT be shown again.

✅ API key created successfully

  Name : iam-core
  IPs  : 127.0.0.1
  Key  : 85a339154b4fb7dd5f90c73f31fedc4d02b550d115264b280ac33db8e51eb09d

⚠️  Save this key now — it will NOT be shown again.

---

# تشغيل مع الـ server الـ local
export CD_API_KEY=your_raw_key
php scripts/test_enqueue.php

# أو على server تاني
CD_BASE_URL=http://localhost:8082 CD_API_KEY=85a339154b4fb7dd5f90c73f31fedc4d02b550d115264b280ac33db8e51eb09d php scripts/test_enqueue.php