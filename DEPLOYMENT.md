# FraudShield Live Deployment

FraudShield is a PHP + MySQL project. The current code cannot use MongoDB Atlas directly because Atlas is MongoDB, while the backend uses PDO MySQL, SQL views, stored procedures, triggers, and MySQL-specific schema logic.

Use Railway with MySQL for the smoothest deployment. Render can host the PHP app with Docker, but you still need an external MySQL database such as Railway MySQL, Aiven MySQL, PlanetScale, or AWS RDS MySQL.

## Option 1: Railway App + Railway MySQL

1. Push this project to GitHub.
2. Open Railway and create a new project.
3. Choose `Deploy from GitHub repo`.
4. Select the FraudShield repository.
5. Add a `MySQL` database service in the same Railway project.
6. Open the web app service, then attach/reference the MySQL variables.
7. Railway commonly provides these variables automatically:

```text
MYSQLHOST
MYSQLPORT
MYSQLUSER
MYSQLPASSWORD
MYSQLDATABASE
```

8. Deploy the app.
9. Open the generated Railway domain.

The first page load initializes the database tables, views, procedures, triggers, and demo data automatically.

## Option 2: Render App + External MySQL

1. Push this project to GitHub.
2. Create a MySQL database on Railway, Aiven, PlanetScale, or AWS RDS.
3. Copy the database connection values.
4. In Render, create a new `Web Service`.
5. Select this GitHub repository.
6. Choose Docker deployment. Render will use the included `Dockerfile`.
7. Add environment variables:

```text
DB_HOST=your-mysql-host
DB_PORT=3306
DB_USER=your-mysql-user
DB_PASS=your-mysql-password
DB_NAME=your-mysql-database
DB_AUTO_CREATE=false
```

8. Deploy the service.
9. Open the Render URL.

## Option 3: Single Database URL

Instead of separate variables, you can set one of these:

```text
DATABASE_URL=mysql://user:password@host:3306/database
MYSQL_URL=mysql://user:password@host:3306/database
```

For hosted databases, keep:

```text
DB_AUTO_CREATE=false
```

## Local XAMPP Setup Still Works

If no environment variables are set, the app keeps using the original local defaults:

```text
DB_HOST=localhost
DB_USER=root
DB_PASS=
DB_NAME=fraudshield
DB_AUTO_CREATE=true
```

## Login

The seeded admin account is:

```text
Email: admin@fraudshield.com
Password: admin123
```

## MongoDB Atlas Note

MongoDB Atlas would require rewriting the backend from MySQL/PDO to MongoDB queries and replacing SQL views, procedures, and triggers with application logic or MongoDB aggregation pipelines. That is a larger migration, not a deployment-only change.
