#!/bin/bash

# Station LocalStack Initialization Script
# Creates SQS queues and SNS topics for local development

set -e

echo "Initializing Station AWS resources..."

# Create Dead Letter Queue first
awslocal sqs create-queue \
    --queue-name station-dlq \
    --attributes '{
        "MessageRetentionPeriod": "1209600",
        "VisibilityTimeout": "30"
    }'

# Get DLQ ARN
DLQ_ARN=$(awslocal sqs get-queue-attributes \
    --queue-url http://sqs.us-east-1.localhost.localstack.cloud:4566/000000000000/station-dlq \
    --attribute-names QueueArn \
    --query 'Attributes.QueueArn' \
    --output text)

# Create main queues with DLQ redrive policy
awslocal sqs create-queue \
    --queue-name station-default \
    --attributes "{
        \"VisibilityTimeout\": \"90\",
        \"MessageRetentionPeriod\": \"345600\",
        \"RedrivePolicy\": \"{\\\"deadLetterTargetArn\\\":\\\"${DLQ_ARN}\\\",\\\"maxReceiveCount\\\":\\\"3\\\"}\"
    }"

awslocal sqs create-queue \
    --queue-name station-high \
    --attributes "{
        \"VisibilityTimeout\": \"90\",
        \"MessageRetentionPeriod\": \"345600\",
        \"RedrivePolicy\": \"{\\\"deadLetterTargetArn\\\":\\\"${DLQ_ARN}\\\",\\\"maxReceiveCount\\\":\\\"3\\\"}\"
    }"

awslocal sqs create-queue \
    --queue-name station-low \
    --attributes "{
        \"VisibilityTimeout\": \"300\",
        \"MessageRetentionPeriod\": \"345600\",
        \"RedrivePolicy\": \"{\\\"deadLetterTargetArn\\\":\\\"${DLQ_ARN}\\\",\\\"maxReceiveCount\\\":\\\"3\\\"}\"
    }"

# Create FIFO queues for ordered processing
awslocal sqs create-queue \
    --queue-name station-ordered.fifo \
    --attributes '{
        "FifoQueue": "true",
        "ContentBasedDeduplication": "true",
        "VisibilityTimeout": "90"
    }'

# Create default queue for simple usage
awslocal sqs create-queue \
    --queue-name default \
    --attributes "{
        \"VisibilityTimeout\": \"90\",
        \"MessageRetentionPeriod\": \"345600\",
        \"RedrivePolicy\": \"{\\\"deadLetterTargetArn\\\":\\\"${DLQ_ARN}\\\",\\\"maxReceiveCount\\\":\\\"3\\\"}\"
    }"

# Create SNS topics for pub/sub patterns
awslocal sns create-topic --name station-events
awslocal sns create-topic --name station-notifications

# Get topic ARNs
EVENTS_ARN=$(awslocal sns list-topics --query "Topics[?ends_with(TopicArn, 'station-events')].TopicArn" --output text)

# Subscribe SQS queues to SNS topics
DEFAULT_QUEUE_ARN=$(awslocal sqs get-queue-attributes \
    --queue-url http://sqs.us-east-1.localhost.localstack.cloud:4566/000000000000/station-default \
    --attribute-names QueueArn \
    --query 'Attributes.QueueArn' \
    --output text)

awslocal sns subscribe \
    --topic-arn "$EVENTS_ARN" \
    --protocol sqs \
    --notification-endpoint "$DEFAULT_QUEUE_ARN"

echo "Station AWS resources initialized successfully!"
echo ""
echo "SQS Queues:"
awslocal sqs list-queues --query 'QueueUrls' --output table
echo ""
echo "SNS Topics:"
awslocal sns list-topics --query 'Topics[*].TopicArn' --output table
