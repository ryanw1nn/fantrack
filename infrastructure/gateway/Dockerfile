FROM nginx:alpine

# Install envsubst (part of gettext) for variable substitution, bash for the entrypoint script, and jq for JSON parsing
RUN apk add --no-cache gettext bash nano jq bind-tools

# Create working directory
WORKDIR /etc/nginx

# Remove the default Nginx config
RUN rm /etc/nginx/conf.d/default.conf

# Copy the Nginx config template that uses env variables (e.g., ${SSL_CERTIFICATE_PATH})
COPY gateway/conf.d/default.conf.template /etc/nginx/templates/default.conf.template
# Snippet templates consumed by entrypoint.sh to assemble per-service location blocks
COPY gateway/templates/conforming_service_template     /etc/nginx/templates/conforming_service_template
COPY gateway/templates/authenticating_service_template /etc/nginx/templates/authenticating_service_template
COPY gateway/registrar.json /etc/nginx/registrar.json
COPY gateway/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Set entrypoint and run Nginx after variable substitution
ENTRYPOINT ["/entrypoint.sh"]