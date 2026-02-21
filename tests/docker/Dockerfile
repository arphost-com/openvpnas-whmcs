FROM php:8.2-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Create non-root user for security (DS002)
RUN groupadd -g 1001 phpuser && \
    useradd -u 1001 -g phpuser -m -s /bin/bash phpuser

WORKDIR /work

# Set ownership of work directory
RUN chown -R phpuser:phpuser /work

# Switch to non-root user
USER phpuser

# Healthcheck for security compliance (DS026)
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD php -v || exit 1

CMD ["php", "tests/smoke.php"]
