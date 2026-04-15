# Wallos Remastered 防刷站部署指南

本文档是开源版的通用运维说明，刻意不包含任何私有域名、私有目录、私有节点地理位置或个人部署拓扑。

适用场景：

- Wallos 运行在源站机器上
- 公网入口由一台反向代理服务器承担
- 代理层使用 `nginx`
- 异常 IP 处理使用 `fail2ban`
- 源站与公网入口之间可以是 `frp`、内网专线、WireGuard、Tailscale 或其他安全隧道

如果你不是用 `frp`，也可以继续参考本文，把其中的上游地址替换成你自己的代理上游即可。

---

## 一、先明确分层职责

推荐把防护拆成三层：

1. 公网网关层：`nginx`
   - 负责连接数限制
   - 负责请求速率限制
   - 负责日志记录
2. 自动封禁层：`fail2ban`
   - 负责把持续命中异常规则的 IP 临时封禁
3. 应用层：Wallos
   - 负责登录失败限制
   - 负责普通用户接口限速
   - 负责普通用户图片上传/下载限速
   - 负责记录安全异常

不建议在应用层限制纯前端动作，例如：

- 单纯切换页面
- 展开/关闭弹窗
- 切换标签页

真正应该限制的是：

- 登录尝试
- 注册尝试
- 密码找回
- 登录后的接口请求
- 图片上传与下载

---

## 二、推荐的通用网络结构

建议采用下面这种拓扑：

1. 用户访问 `https://<your-public-domain>`
2. 请求先到公网网关服务器上的 `nginx`
3. `nginx` 负责 TLS、反代、限速、连接限制、日志
4. `nginx` 把请求转发给受控上游
5. 受控上游再把流量送到源站上的 Wallos
6. Wallos 再做鉴权、业务校验、应用层限速与异常记录

其中第 4 步的“受控上游”可以是：

- `frp`
- 内网反代
- VPN 隧道
- 零信任隧道
- 同一台服务器上的本地上游服务

关键点只有一个：

- 源站不要被公网直接绕过访问

---

## 三、先确认源站不能被公网直连

如果源站还能被公网直接访问，那么你在网关层做的限速和封禁都可能被绕过。

上线前请检查：

1. 源站服务端口是否暴露给公网
2. 路由器或云防火墙是否把外网流量直接放行到了源站
3. 源站主机防火墙是否只允许可信链路访问 Wallos

推荐状态：

- 只有公网网关服务器或可信隧道可以访问源站
- 源站本身不接受任意公网 IP 的直接访问

---

## 四、在公网网关服务器安装基础组件

以下命令以 Debian / Ubuntu 为例。

### 4.1 安装软件

```bash
sudo apt update
sudo apt install -y nginx fail2ban ufw
```

### 4.2 放行必要端口

```bash
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

### 4.3 给 SSH 做基础收敛

```bash
sudo ufw limit 22/tcp
```

---

## 五、给 Wallos 站点准备独立日志

先在 `nginx.conf` 的 `http {}` 里增加一个专用日志格式：

```nginx
log_format wallos_main '$remote_addr - $remote_user [$time_local] '
                       '"$request" $status $body_bytes_sent '
                       '"$http_referer" "$http_user_agent" '
                       'rt=$request_time uct="$upstream_connect_time" urt="$upstream_response_time"';
```

然后在 Wallos 站点配置中指定：

```nginx
access_log /var/log/nginx/wallos.access.log wallos_main;
error_log  /var/log/nginx/wallos.error.log warn;
```

这样后续 `fail2ban` 可以只看 Wallos 站点日志，不会误伤其他站点。

---

## 六、在 Nginx 增加速率限制和连接限制

### 6.1 在 `http {}` 里定义限制区域

```nginx
limit_req_status 429;

limit_req_zone  $binary_remote_addr zone=wallos_login_zone:10m    rate=10r/m;
limit_req_zone  $binary_remote_addr zone=wallos_register_zone:10m rate=5r/m;
limit_req_zone  $binary_remote_addr zone=wallos_reset_zone:10m    rate=5r/m;
limit_req_zone  $binary_remote_addr zone=wallos_api_zone:20m      rate=180r/m;
limit_req_zone  $binary_remote_addr zone=wallos_media_zone:20m    rate=150r/m;

limit_conn_zone $binary_remote_addr zone=wallos_conn_zone:20m;
```

含义如下：

- 登录页：每 IP 每分钟 10 次
- 注册页：每 IP 每分钟 5 次
- 找回密码页：每 IP 每分钟 5 次
- 通用接口：每 IP 每分钟 180 次
- 图片访问：每 IP 每分钟 150 次
- 并发连接：后续按站点配置限制

这是一个偏稳妥的起始值，不是绝对标准值。

---

## 七、Wallos 站点配置示例

下面是一份脱敏后的通用示例。请把占位符替换成你自己的值：

- `<your-public-domain>`
- `<upstream-host>`
- `<upstream-port>`

```nginx
upstream wallos_upstream {
    server <upstream-host>:<upstream-port>;
    keepalive 32;
}

server {
    listen 80;
    server_name <your-public-domain>;

    access_log /var/log/nginx/wallos.access.log wallos_main;
    error_log  /var/log/nginx/wallos.error.log warn;

    limit_conn wallos_conn_zone 30;

    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header Connection "";

    location = /login.php {
        limit_req zone=wallos_login_zone burst=8 nodelay;
        proxy_pass http://wallos_upstream;
    }

    location = /registration.php {
        limit_req zone=wallos_register_zone burst=5 nodelay;
        proxy_pass http://wallos_upstream;
    }

    location = /passwordreset.php {
        limit_req zone=wallos_reset_zone burst=5 nodelay;
        proxy_pass http://wallos_upstream;
    }

    location ^~ /endpoints/ {
        limit_req zone=wallos_api_zone burst=80 nodelay;
        proxy_pass http://wallos_upstream;
    }

    location = /endpoints/media/subscriptionimage.php {
        limit_req zone=wallos_media_zone burst=60 nodelay;
        proxy_pass http://wallos_upstream;
    }

    location / {
        proxy_pass http://wallos_upstream;
    }
}
```

如果你使用 `frp`，通常 `<upstream-host>:<upstream-port>` 会写成网关服务器本地监听的 FRP 上游地址。

如果你不是用 `frp`，就改成你自己的反代上游地址。

---

## 八、为什么网关层不要比应用层更苛刻

Wallos 自身已经有普通用户级别的应用层限速。

如果 Nginx 配得过于严格，会出现：

- 正常用户还没进应用层就被 429
- 多人共用一个出口 IP 时被误伤

推荐策略是：

- 匿名敏感页面：网关层更严
- 登录后普通 API / 图片：网关层适度放宽，应用层负责更细的用户级限制

---

## 九、先验证 Nginx 限速是否生效

改完配置后执行：

```bash
sudo nginx -t
sudo systemctl reload nginx
```

### 9.1 基础连通性检查

```bash
curl -I http://<your-public-domain>/
curl -I http://<your-public-domain>/login.php
```

### 9.2 人工触发一轮限速

```bash
for i in $(seq 1 20); do
  curl -s -o /dev/null -w "%{http_code}\n" http://<your-public-domain>/login.php
done
```

开始出现 `429` 说明网关限速生效。

### 9.3 查看 Nginx 日志

```bash
sudo tail -f /var/log/nginx/wallos.access.log
```

---

## 十、配置 Fail2ban 自动封禁恶意 IP

### 10.1 创建 `429` 过滤器

```bash
sudo nano /etc/fail2ban/filter.d/wallos-nginx-429.conf
```

内容：

```ini
[Definition]
failregex = ^<HOST> - .* "(GET|POST|HEAD) .* HTTP/.*" 429 .*
ignoreregex =
```

### 10.2 创建登录类敏感入口过滤器

```bash
sudo nano /etc/fail2ban/filter.d/wallos-auth-abuse.conf
```

内容：

```ini
[Definition]
failregex = ^<HOST> - .* "(GET|POST|HEAD) /(login\.php|registration\.php|passwordreset\.php) HTTP/.*" 429 .*
ignoreregex =
```

### 10.3 创建 jail 配置

```bash
sudo nano /etc/fail2ban/jail.d/wallos.local
```

内容：

```ini
[wallos-nginx-429]
enabled  = true
filter   = wallos-nginx-429
logpath  = /var/log/nginx/wallos.access.log
backend  = auto
findtime = 600
maxretry = 20
bantime  = 3600
action   = %(action_)s

[wallos-auth-abuse]
enabled  = true
filter   = wallos-auth-abuse
logpath  = /var/log/nginx/wallos.access.log
backend  = auto
findtime = 600
maxretry = 8
bantime  = 7200
action   = %(action_)s
```

含义：

- 全站 10 分钟内连续触发 20 次 `429`，封 1 小时
- 登录/注册/找回密码 10 分钟内连续触发 8 次 `429`，封 2 小时

### 10.4 启动并检查

```bash
sudo systemctl enable fail2ban
sudo systemctl restart fail2ban
sudo fail2ban-client status
sudo fail2ban-client status wallos-nginx-429
sudo fail2ban-client status wallos-auth-abuse
```

---

## 十一、Wallos 应用层与网关层如何配合

推荐分工：

### 11.1 网关层负责

- 按 IP 限制匿名访问节奏
- 限制单 IP 并发连接
- 在请求进入 PHP 之前就拦截明显异常流量
- 把持续命中异常规则的来源交给 `fail2ban`

### 11.2 应用层负责

- 登录失败限制
- 普通用户接口限速
- 普通用户图片上传/下载限速
- 记录安全异常供管理员查看

---

## 十二、管理员入口建议额外保护

如果你选择让管理员账号在应用层不受普通限速影响，那么管理员入口更适合前移保护。

可以选其中一种或多种：

1. 管理员入口只允许固定 IP
2. 给 `/admin.php` 和 `/endpoints/admin/` 再加一层 `nginx` Basic Auth
3. 强制管理员启用 2FA
4. 给管理员相关路径配更严格的网关层速率限制

例如你还可以单独加：

```nginx
location = /admin.php {
    limit_req zone=wallos_login_zone burst=5 nodelay;
    proxy_pass http://wallos_upstream;
}

location ^~ /endpoints/admin/ {
    limit_req zone=wallos_api_zone burst=20 nodelay;
    proxy_pass http://wallos_upstream;
}
```

这类规则的目的不是给管理员做业务层统计，而是保护公网入口不被恶意打爆。

---

## 十三、推荐起步参数

如果你是中小规模部署，可以从下面这组值开始：

### 13.1 网关层

- 登录页：`10r/m`
- 注册页：`5r/m`
- 找回密码页：`5r/m`
- 后端接口：`180r/m`
- 图片访问：`150r/m`
- 单 IP 并发连接：`30`

### 13.2 Fail2ban

- 全站连续 `429`：10 分钟 20 次，封 1 小时
- 登录/注册/找回密码 `429`：10 分钟 8 次，封 2 小时

### 13.3 Wallos 应用层

建议结合后台的速率限制预设，从“常规”或“推荐常规”开始，然后按你的用户规模微调。

---

## 十四、上线后必须检查的项目

### 14.1 检查 Nginx 语法

```bash
sudo nginx -t
```

### 14.2 检查 Nginx 状态

```bash
sudo systemctl reload nginx
sudo systemctl status nginx
```

### 14.3 检查 Fail2ban

```bash
sudo systemctl restart fail2ban
sudo systemctl status fail2ban
sudo fail2ban-client status
```

### 14.4 检查 Wallos 健康接口

如果 Wallos 使用本地默认示例端口，可以这样检查：

```bash
curl -I http://127.0.0.1:18282/health.php
```

如果你改过端口，请替换成你自己的服务端口。

### 14.5 检查后台异常记录

进入 Wallos 管理员后台确认：

- 速率限制配置已启用
- 安全异常弹窗能看到命中记录
- 访问日志弹窗能看到对应请求

---

## 十五、常见误区

### 15.1 不要把页面切换速度当成主要限制对象

真正有意义的是：

- 登录
- 注册
- 找回密码
- 登录后接口
- 图片资源

### 15.2 不要只靠 PHP 扛公网恶意流量

连接级和匿名级的收敛应该由 `nginx` 与系统防火墙先承担。

### 15.3 不要让源站直接暴露公网

否则网关层限速、日志和封禁都可能被绕过。

---

## 十六、建议的实施顺序

1. 先确认源站不会被公网直连
2. 再给 Wallos 站点拆独立日志
3. 再上 `limit_req` 和 `limit_conn`
4. 验证 `429` 是否正常触发
5. 接入 `fail2ban`
6. 再验证封禁是否生效
7. 最后检查 Wallos 后台的异常记录是否正常

---

## 十七、后续可以继续做什么

如果你还想继续加强，可以进一步加入：

- 管理员入口 IP 白名单
- Basic Auth 保护后台
- 更细的地区级访问控制
- 更细的日志告警和监控
- WAF 或 CDN 层保护

