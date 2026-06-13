module.exports = {
  apps: [{
    name: 'php-server',
    script: '/usr/bin/php8.1',
    args: '-S 0.0.0.0:3000 router.php',
    cwd: '/home/user/webapp',
    watch: false,
    instances: 1,
    exec_mode: 'fork',
    env: { PORT: 3000 }
  }]
}
