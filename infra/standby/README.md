# infra/standby

Standby/failover configuration for the hosting environment (MariaDB replication, Redis
failover, static egress IP handling). The static egress IP is a hard dependency under
change control — OPay IP-whitelists in both directions (`REQ-HOST-002`, `REQ-PAY-011`).
