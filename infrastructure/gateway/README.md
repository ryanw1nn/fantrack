# Gateway Service Configuration

The API Gateway serves as a reverse proxy for all microservices in the fantasy-app platform. It handles routing, authentication validation, and service availability.

## Service Configuration

The gateway supports enabling/disabling services using environment variables with service names directly. This allows you to control which services are available without modifying the configuration files directly.

### How to Configure Services

1. Edit the `.env` file in the gateway directory:

```bash
# core
AUTH_SERVICE=1
PERMISSION_SERVICE=1

# bidding-module
# BID_SERVICE=1
# PROPOSAL_SERVICE=1
# SCOPE_SERVICE=1

# hrm-module
# EMPLOYEE_SERVICE=1
# EMPLOYMENT_SERVICE=1
# WAGE_SERVICE=1

# project-module
PROJECT_SERVICE=1

# crm-module
# CUSTOMER_SERVICE=1

# estimating-module
BILLABLE_SERVICE=1

# infrastructure
ADMINER=1
PORTAINER=1
EVENTBUS=1

# auth gateway behavior when AUTH is disabled: mock|strict
AUTH_MODE=mock
```

1. Set the value to `1` to enable a service. To disable a service, either omit it, comment it out, or set it to any value other than `1`.

### Special Configuration for Authentication

When `AUTH_SERVICE` is not set to `1`, you can control how the gateway handles authentication requests using the `AUTH_MODE` variable:

- `AUTH_MODE=mock`: Authentication requests will be automatically approved (useful for development)
- `AUTH_MODE=strict`: Authentication requests will fail with a 503 error (default behavior)

### How It Works

When the gateway starts:

1. The entrypoint script reads the service environment variables
2. For each service not set to `1`, it comments out the corresponding location block in the Nginx configuration
3. For enabled services (set to `1`), it keeps the location blocks as they are
4. If AUTH_SERVICE is not enabled, it handles authentication based on the AUTH_MODE setting

This approach allows you to:

- Run a minimal set of services during development
- Disable services that aren't needed for specific deployments
- Test different service configurations without modifying the Nginx configuration files

## Environment Variables

| Variable | Description | Default |
| -------- | ----------- | ------- |
| `AUTH_SERVICE` | Enable/disable auth service | 1 |
| `PERMISSION_SERVICE` | Enable/disable permission service | 1 |
| `PROJECT_SERVICE` | Enable/disable project service | 1 |
| `BILLABLE_SERVICE` | Enable/disable billable service | 1 |
| `BID_SERVICE` | Enable/disable bid service | 0 |
| `PROPOSAL_SERVICE` | Enable/disable proposal service | 0 |
| `SCOPE_SERVICE` | Enable/disable scope service | 0 |
| `EMPLOYEE_SERVICE` | Enable/disable employee service | 0 |
| `EMPLOYMENT_SERVICE` | Enable/disable employment service | 0 |
| `WAGE_SERVICE` | Enable/disable wage service | 0 |
| `CUSTOMER_SERVICE` | Enable/disable customer service | 0 |
| `ADMINER` | Enable/disable Adminer database tool | 1 |
| `PORTAINER` | Enable/disable Portainer container management | 1 |
| `EVENTBUS` | Enable/disable RabbitMQ event bus UI | 1 |
| `AUTH_MODE` | Authentication mode when AUTH_SERVICE is disabled | strict |
| `SSL_CERTIFICATE_PATH` | Path to SSL certificates | /etc/nginx/certs |
