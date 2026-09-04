FROM node:20 AS frontend-builder

ARG FRONTEND_REF=main

RUN git clone https://github.com/Davis-Wiki-Revival/davispedia-frontend.git /frontend
WORKDIR /frontend

RUN git checkout "${FRONTEND_REF}"

RUN npm ci && npm run build


FROM mediawiki:1.46

RUN mkdir -p \
    /var/www/html/extensions/DavispediaFrontend \
    /var/www/html/extensions/Cowlender

COPY --from=frontend-builder /frontend/extension.json /var/www/html/extensions/DavispediaFrontend/
COPY --from=frontend-builder /frontend/includes /var/www/html/extensions/DavispediaFrontend/includes/
COPY --from=frontend-builder /frontend/dist /var/www/html/extensions/DavispediaFrontend/dist/
COPY extensions/Cowlender /var/www/html/extensions/Cowlender/

RUN chown -R www-data:www-data \
    /var/www/html/extensions/DavispediaFrontend \
    /var/www/html/extensions/Cowlender
