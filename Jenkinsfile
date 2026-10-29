pipeline {
    agent any

    options {
        timestamps()
        disableConcurrentBuilds()
    }

    stages {
        stage('Checkout') {
            steps {
                checkout scm
            }
        }

        stage('PHP Syntax Check') {
            steps {
                sh '''
                    set -e
                    if ! command -v php >/dev/null 2>&1; then
                        echo "PHP CLI tidak tersedia pada agent Jenkins."
                        exit 127
                    fi
                    php -v
                    find . -type f -name '*.php' -not -path './vendor/*' -print | while IFS= read -r file; do
                        php -l "$file"
                    done
                '''
            }
        }

        stage('Unit Tests') {
            steps {
                sh '''
                    set -e
                    php tests/run-unit.php
                '''
            }
        }

        stage('Offline Specification Tests') {
            steps {
                sh '''
                    set -e
                    php tests/run-offline-spec.php
                '''
            }
        }

        stage('Static Security Analysis') {
            steps {
                sh '''
                    set -e
                    php tests/run-static.php
                '''
            }
        }
    }

    post {
        always {
            echo 'Pipeline fondasi selesai. Tahap ini tidak melakukan deployment.'
        }
    }
}
