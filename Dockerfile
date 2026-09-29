FROM node:20 AS frontend-builder

ARG FRONTEND_REF=main

RUN git clone https://github.com/Davis-Wiki-Revival/davispedia-frontend.git /frontend

WORKDIR /frontend

RUN git checkout "${FRONTEND_REF}" \
    && npm ci \
    && npm run build


FROM mediawiki:1.46

COPY --from=frontend-builder --chown=www-data:www-data \
    /frontend/extension.json \
    /var/www/html/extensions/DavispediaFrontend/extension.json

COPY --from=frontend-builder --chown=www-data:www-data \
    /frontend/includes/ \
    /var/www/html/extensions/DavispediaFrontend/includes/

COPY --from=frontend-builder --chown=www-data:www-data \
    /frontend/dist/ \
    /var/www/html/extensions/DavispediaFrontend/dist/

COPY --chown=www-data:www-data \
    extensions/Cowlender/ \
    /var/www/html/extensions/Cowlender/

COPY --chown=www-data:www-data \
    extensions/PictureOfTheDay/ \
    /var/www/html/extensions/PictureOfTheDay/

# Fail the build immediately if a required extension was not packaged.
RUN test -f /var/www/html/extensions/DavispediaFrontend/extension.json \
    && test -f /var/www/html/extensions/Cowlender/extension.json \
    && test -f /var/www/html/extensions/PictureOfTheDay/extension.json