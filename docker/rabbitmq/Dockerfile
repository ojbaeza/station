FROM rabbitmq:4-management-alpine

# Download and install the delayed message exchange plugin
# This plugin is required for Station's delayed job functionality
# See: https://github.com/rabbitmq/rabbitmq-delayed-message-exchange/releases
RUN wget -O $RABBITMQ_HOME/plugins/rabbitmq_delayed_message_exchange-4.2.0.ez \
    https://github.com/rabbitmq/rabbitmq-delayed-message-exchange/releases/download/v4.2.0/rabbitmq_delayed_message_exchange-4.2.0.ez && \
    rabbitmq-plugins enable --offline rabbitmq_delayed_message_exchange

# Copy custom configuration
COPY rabbitmq.conf /etc/rabbitmq/rabbitmq.conf
COPY definitions.json /etc/rabbitmq/definitions.json
