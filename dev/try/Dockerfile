# syntax=docker/dockerfile:1.7
#
# LedgerDirect for PrestaShop — try-it image.
#
# PrestaShop Flashlight (PrestaShop's own pre-installed shop image, booting from a database dump)
# with the module from this checkout, on the XRP Ledger testnet. The dump is loaded and the module
# installed on the first start, against the database of dev/try/compose.yaml; see docker/entrypoint.sh.
#
# PrestaShop is licensed under OSL-3.0 (core) and AFL-3.0 (modules, themes); the Flashlight image is
# MIT. Sources and licence files ship inside this image under /var/www/html. "PrestaShop" is a
# trademark of PrestaShop SA; this image is not an official PrestaShop image.
#
# Build from the repository root:
#   docker build -f dev/try/Dockerfile -t try-prestashop .

ARG FLASHLIGHT_IMAGE=prestashop/prestashop-flashlight:9.1.5-8.3-nginx@sha256:94b2d8882a8b0e3382e75878a0120cc6e97b5566abb2640e1cd7272da58900a2
ARG EXTENSION_INSTALLER=mlocati/php-extension-installer@sha256:35821db0661308f64aa18483bb3af56bedc3b40090ff441ed961327d1ccbceb8

FROM ${EXTENSION_INSTALLER} AS extension-installer

FROM ${FLASHLIGHT_IMAGE} AS runtime
# The version the module is labelled with. Release builds pass the git tag.
ARG LD_VERSION=dev-try
USER root
COPY --from=extension-installer /usr/bin/install-php-extensions /usr/local/bin/
# gmp for the wallet tool's signing (hardcastle/xrpl_php); DejaVu to draw the demo article's picture.
RUN install-php-extensions gmp \
 && apk add --no-cache font-dejavu \
 && rm /usr/local/bin/install-php-extensions

WORKDIR /var/www/html
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_CACHE_DIR=/var/cache/composer
# Flashlight writes the database settings at every container start; this stack's are fixed, so they
# go into the image once (service "db", see compose.yaml), the same way. Blackfire stays off.
RUN sed -i \
      -e "s~host' => '127.0.0.1'~host' => 'db'~" \
      -e "s~database_engine' => 'InnoDB',~database_engine' => 'InnoDB',\n    'server_version' => '5.7',~" \
      app/config/parameters.php \
 && sed -i -E 's~^(.+)$~;\1~g' "$PHP_INI_DIR/conf.d/blackfire.ini"
# German, for the shop's second language: PrestaShop downloads its language pack from
# i18n.prestashop-project.org; it is fetched here, so the first start needs no download.
RUN curl -fsS --retry 3 -o translations/sf-de-DE.zip \
      "https://i18n.prestashop-project.org/translations/${PS_VERSION}/de-DE/de-DE.zip" \
 && unzip -qo translations/sf-de-DE.zip -d translations \
 && chown -R www-data:www-data translations

# The module from this checkout with its runtime dependencies, as the release zip bundles them. Of
# dev/ only the end-to-end harness helper stays (dev/bin/e2e.php, LD_E2E_PRESTASHOP_COMPOSE_DIR).
COPY . /var/www/html/modules/ledgerdirect
RUN --mount=type=cache,target=/var/cache/composer \
    cd modules/ledgerdirect \
 && composer install --no-interaction --no-progress --no-dev --optimize-autoloader --classmap-authoritative \
 && find dev -mindepth 1 -maxdepth 1 ! -name bin -exec rm -rf {} + \
 && find dev/bin -type f ! -name e2e.php ! -name index.php -delete \
 && chown -R www-data:www-data /var/www/html/modules/ledgerdirect

# The shop's testnet wallet tool (hardcastle/xrpl_php), in a vendor/ of its own: it runs as a separate
# process, so its dependencies never meet PrestaShop's or the module's.
COPY dev/try/wallet/composer.json /opt/ledger-direct/wallet/composer.json
RUN --mount=type=cache,target=/var/cache/composer \
    composer install --no-interaction --no-progress --no-dev --working-dir=/opt/ledger-direct/wallet

COPY dev/try/docker/entrypoint.sh dev/try/docker/install.php dev/try/docker/wallet.php /usr/local/share/ledger-direct/
# The miniatures (catalog.json and pictures; their licence is in dev/try/catalog/NOTICE.md).
COPY dev/try/catalog/catalog.json dev/try/catalog/NOTICE.md /usr/local/share/ledger-direct/catalog/
COPY dev/try/catalog/images /usr/local/share/ledger-direct/catalog/images
# What identifies this build to an existing shop: the module's files. When they differ from the ones
# the shop was set up with (an updated image on existing volumes), the entrypoint runs the module's
# upgrade. The same for the catalogue: a new one is imported into an existing shop on its next start.
RUN chmod 0755 /usr/local/share/ledger-direct/entrypoint.sh \
 && (cd modules/ledgerdirect && find . -type f ! -path './vendor/*' | LC_ALL=C sort | xargs sha256sum | sha256sum | cut -c1-16) \
      > /usr/local/share/ledger-direct/build-id \
 && (cd /usr/local/share/ledger-direct/catalog && cat catalog.json images/* | sha256sum | cut -c1-16) \
      > /usr/local/share/ledger-direct/catalog-id \
 && echo "$PS_VERSION" > /usr/local/share/ledger-direct/prestashop-version \
 && install -d -o www-data -g www-data -m 0700 /var/lib/ledger-direct

LABEL org.opencontainers.image.title="LedgerDirect for PrestaShop — try it" \
      org.opencontainers.image.description="PrestaShop with the LedgerDirect XRPL payment module, on the XRP Ledger testnet. For trying the module, not for production." \
      org.opencontainers.image.source="https://github.com/ledger-direct/ledger-direct-prestashop" \
      org.opencontainers.image.licenses="MIT AND OSL-3.0 AND AFL-3.0" \
      org.opencontainers.image.version="${LD_VERSION}" \
      com.ledger-direct.prestashop.version="9.1.5"
USER www-data
HEALTHCHECK --interval=10s --timeout=5s --start-period=600s --retries=3 \
  CMD curl -fs -o /dev/null http://127.0.0.1/ || exit 1
ENTRYPOINT ["/usr/local/share/ledger-direct/entrypoint.sh"]
