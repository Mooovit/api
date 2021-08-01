#!/bin/bash
# This is the deploy script for mooovit

# Zip the project
zip /tmp/$TRAVIS_BUILD_NUMBER.zip -qq -r * .[^.]* -x ".git/*"

# Copy the zip on the Amazon S3 Bucket
aws s3 cp /tmp/$TRAVIS_BUILD_NUMBER.zip s3://$AWS_S3_BUCKET/$PROJECT/$TRAVIS_BUILD_NUMBER.zip

# Download the version on the staging server
curl -s $SERVERMANAGER_STAGING/api/tokenaction/$TOKEN_SERVERMANAGER/$PROJECT/downloadVersion/$TRAVIS_BUILD_NUMBER > /dev/null

# Deploy the version on the staging server
curl $SERVERMANAGER_STAGING/api/tokenaction/$TOKEN_SERVERMANAGER/$PROJECT/deploy/$TRAVIS_BUILD_NUMBER
curl $SERVERMANAGER_STAGING/api/tokenaction/$TOKEN_SERVERMANAGER/$PROJECT/migrate/$TRAVIS_BUILD_NUMBER

# Download the version on the production server
curl -s $SERVERMANAGER_PROD/api/tokenaction/$TOKEN_SERVERMANAGER/$PROJECT/downloadVersion/$TRAVIS_BUILD_NUMBER > /dev/null

git config user.email "build@travis.com"
git config user.name "Travis Build"
git tag "t$TRAVIS_BUILD_NUMBER" -a -m "Travis build $TRAVIS_BUILD_NUMBER"
# Todo - Find Git Token to push
# git push https://$CI_GITLAB_USER:$CI_GITLAB_TOKEN@gitlab.com/$CI_PROJECT_PATH/ --tags
