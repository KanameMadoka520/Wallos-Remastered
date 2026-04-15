# Wallos Remastered 防刷站部署指南

本文档适用于你当前这类部署方式：

- Wallos 实际运行在自己的物理机 / 家用服务器
- 通过 `frp` 把服务暴露到公网
- 日本东京 VPS 作为公网网关
- VPS 上使用 `nginx` 对外提供 `80/443`
- 当前没有接入 Cloudflare

本文档目标不是替代 Wallos 程序内已经做好的限速，而是补齐真正的运维闭环：

- 网关层先拦掉恶意刷站和连接洪泛
- 应用层再处理普通用户的精细化限速
- 异常 IP 继续通过 `fail2ban` 自动封禁

---

## 一、先理解应该限制什么，不该限制什么

当前建议的收敛原则如下：

- 要限制：
  - 登录页
  - 注册页
  - 密码找回页
  - Wallos 登录后的后端接口请求
  - 订阅图片上传和下载
- 不建议在应用层限制：
  - 单纯切换页面
  - 打开/关闭弹窗
  - 切换分页、切换 tab 这类纯前端动作
- 当前程序内已做：
  - 普通用户登录失败限速
  - 普通用户后端请求限速
  - 普通用户订阅图片上传/下载限速
  - 管理员账号豁免这些应用层限速
- 因为管理员现在被应用层豁免，所以更需要在 VPS 网关层补强保护

一句话总结：

- `nginx` 负责挡大流量、快节奏、匿名层面的刷站
- Wallos 负责按用户身份做更细的业务限速
- `fail2ban` 负责把持续触发异常的 IP 临时踢出去

---

## 二、你的推荐防护结构

建议把访问路径固定成下面这样：

1. 用户访问 `https://wallos.tcymc.space`
2. 请求先到日本东京 VPS 的 `nginx`
3. `nginx` 做：
   - TLS
   - 反向代理
   - 速率限制
   - 并发连接限制
   - 访问日志记录
4. `nginx` 再把请求转发给 VPS 本地的 `frp` 入口
5. `frp` 再把流量转发回你自己的物理机上的 Wallos
6. Wallos 再执行应用层权限校验、图片权限校验、用户级限速和异常记录

这意味着真正第一层防线必须在 VPS，而不是 PHP。

---

## 三、先确认源站不要直接裸露公网

这一步非常重要。如果物理机源站还能被公网直接访问，那么 `nginx` 和 `fail2ban` 都会被绕过。

请检查：

1. 你的 Wallos 物理机是否有公网入站端口直接映射到 `18282`
2. 路由器/NAT 是否把外网流量直接转给了物理机
3. 物理机防火墙是否允许任意公网来源访问 Wallos 端口

推荐状态：

- 物理机只接受：
  - 本机访问
  - `frpc` / `frps` 内部转发链路
  - 内网管理访问
- 物理机不要直接对公网开放 Wallos 端口

如果你的物理机本身没有公网 IP，这一步天然较安全；如果有公网 IP，就必须在物理机防火墙上额外收口。

---

## 四、在日本东京 VPS 上安装基础组件

以下命令以 Debian / Ubuntu 为例。

### 4.1 安装 Nginx、Fail2ban、UFW

```bash
sudo apt update
sudo apt install -y nginx fail2ban ufw
```

### 4.2 打开必要端口

```bash
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

如果你已经有自己的防火墙规则，请不要机械照抄，重点是保证：

- 放行 SSH
- 放行 Web 端口
- 不额外开放无用端口

### 4.3 给 SSH 加基础保护

```bash
sudo ufw limit 22/tcp
```

这一步和 Wallos 无直接关系，但非常推荐顺手做掉。

---

## 五、准备 Nginx 日志，方便后面 Fail2ban 工作

先给 Wallos 站点单独准备访问日志，避免和其他站点混在一起。

你可以在 `nginx.conf` 的 `http {}` 里增加下面这段：

```nginx
log_format wallos_main '$remote_addr - $remote_user [$time_local] '
                       '"$request" $status $body_bytes_sent '
                       '"$http_referer" "$http_user_agent" '
                       'rt=$request_time uct="$upstream_connect_time" urt="$upstream_response_time"';
```

然后在你的 Wallos 站点配置里指定：

```nginx
access_log /var/log/nginx/wallos.access.log wallos_main;
error_log  /var/log/nginx/wallos.error.log warn;
```

这样后面 `fail2ban` 可以只盯这个站点，不会误封别的服务。

---

## 六、在 Nginx 里增加限速和连接限制

这一步是重点。

### 6.1 在 `http {}` 级别增加限速 zone

把下面这段放进 `nginx.conf` 的 `http {}` 中：

```nginx
limit_req_status 429;

limit_req_zone  $binary_remote_addr zone=wallos_login_zone:10m    rate=10r/m;
limit_req_zone  $binary_remote_addr zone=wallos_register_zone:10m rate=5r/m;
limit_req_zone  $binary_remote_addr zone=wallos_reset_zone:10m    rate=5r/m;
limit_req_zone  $binary_remote_addr zone=wallos_api_zone:20m      rate=180r/m;
limit_req_zone  $binary_remote_addr zone=wallos_media_zone:20m    rate=150r/m;

limit_conn_zone $binary_remote_addr zone=wallos_conn_zone:20m;
```

这些值的含义：

- `login`：登录页按 IP 每分钟 10 次请求
- `register`：注册页按 IP 每分钟 5 次请求
- `reset`：找回密码按 IP 每分钟 5 次请求
- `api`：普通后端接口按 IP 每分钟 180 次请求
- `media`：图片查看 / 下载按 IP 每分钟 150 次请求
- `limit_conn`：限制单 IP 并发连接数，后面会在站点里使用

这些是比较稳妥的起始值，不算特别激进。

如果你未来有很多用户共用同一个出口 IP，再酌情调高 `api` 和 `media`。

### 6.2 在 Wallos 站点配置里使用这些规则

假设你的 Wallos 域名是 `wallos.tcymc.space`，Nginx 反代到 VPS 本地 FRP 入口，示例可以写成这样：

```nginx
upstream wallos_frp_upstream {
    server 127.0.0.1:18080;
    keepalive 32;
}

server {
    listen 80;
    server_name wallos.tcymc.space;

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
        proxy_pass http://wallos_frp_upstream;
    }

    location = /registration.php {
        limit_req zone=wallos_register_zone burst=5 nodelay;
        proxy_pass http://wallos_frp_upstream;
    }

    location = /passwordreset.php {
        limit_req zone=wallos_reset_zone burst=5 nodelay;
        proxy_pass http://wallos_frp_upstream;
    }

    location ^~ /endpoints/ {
        limit_req zone=wallos_api_zone burst=80 nodelay;
        proxy_pass http://wallos_frp_upstream;
    }

    location = /endpoints/media/subscriptionimage.php {
        limit_req zone=wallos_media_zone burst=60 nodelay;
        proxy_pass http://wallos_frp_upstream;
    }

    location / {
        proxy_pass http://wallos_frp_upstream;
    }
}
```

注意事项：

1. `127.0.0.1:18080` 只是示例，请改成你 VPS 上 `frp` 实际接收 Wallos 的本地端口
2. 如果你已经有 HTTPS 配置，请把上面内容并入现有 `server 443 ssl` 配置块，不要重复建一套冲突配置
3. `proxy_set_header X-Real-IP $remote_addr;` 很重要
   - Wallos 现在会优先信任 `X-Real-IP`
   - 这样前端代理链上的真实来源更稳定

### 6.3 为什么不建议把 Nginx 限速调得比应用层更苛刻

因为现在 Wallos 程序内已经有普通用户级别的限速和图片流量控制。

如果 Nginx 太凶，会出现：

- 还没到应用层，用户就先被网关打 429
- 多个普通用户共用同一出口 IP 时被误伤

所以推荐策略是：

- 匿名敏感页面：Nginx 更严
- 登录后普通 API / 图片：Nginx 稍宽，Wallos 程序内更细

---

## 七、先用 Nginx 完成第一轮验证

改完配置后执行：

```bash
sudo nginx -t
sudo systemctl reload nginx
```

然后用下面几种方式检查：

### 7.1 看站点是否还能正常访问

```bash
curl -I http://wallos.tcymc.space/
curl -I http://wallos.tcymc.space/login.php
```

### 7.2 看限速是否真正触发

例如快速请求登录页：

```bash
for i in $(seq 1 20); do
  curl -s -o /dev/null -w "%{http_code}\n" http://wallos.tcymc.space/login.php
done
```

如果配置生效，你会开始看到 `429`。

### 7.3 看日志

```bash
sudo tail -f /var/log/nginx/wallos.access.log
```

如果你在压测，应该能看到连续的 `429`。

---

## 八、配置 Fail2ban 自动封禁恶意 IP

Nginx 只能限速，不能自动把坏 IP 踢很久。`fail2ban` 就负责这个。

### 8.1 新建 `429` 过滤规则

创建文件：

```bash
sudo nano /etc/fail2ban/filter.d/wallos-nginx-429.conf
```

内容：

```ini
[Definition]
failregex = ^<HOST> - .* "(GET|POST|HEAD) .* HTTP/.*" 429 .*
ignoreregex =
```

这个规则的意思是：

- 如果某个 IP 在 Nginx 日志里频繁打出 `429`
- 说明它已经持续撞到限速
- 那就进一步进入封禁候选

### 8.2 再给登录/注册等敏感入口单独做一个更严格的过滤器

创建文件：

```bash
sudo nano /etc/fail2ban/filter.d/wallos-auth-abuse.conf
```

内容：

```ini
[Definition]
failregex = ^<HOST> - .* "(GET|POST|HEAD) /(login\.php|registration\.php|passwordreset\.php) HTTP/.*" 429 .*
ignoreregex =
```

这个规则更严格，专门盯：

- 登录页
- 注册页
- 找回密码页

### 8.3 新建 jail 配置

创建文件：

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

这套参数含义：

- `wallos-nginx-429`
  - 10 分钟内连续命中 20 次 `429`
  - 封禁 1 小时
- `wallos-auth-abuse`
  - 10 分钟内在登录/注册/找回密码上打出 8 次 `429`
  - 封禁 2 小时

对公开站点来说，这是很实用的起始值。

### 8.4 启动并检查 Fail2ban

```bash
sudo systemctl enable fail2ban
sudo systemctl restart fail2ban
sudo fail2ban-client status
sudo fail2ban-client status wallos-nginx-429
sudo fail2ban-client status wallos-auth-abuse
```

如果状态里能看到 jail，说明已经生效。

---

## 九、怎么让 Wallos 程序内限速和 VPS 限速互相配合

推荐你这样分工：

### 9.1 VPS / Nginx 层负责

- 按 IP 限制匿名访问速率
- 限制单 IP 并发连接数
- 在访问还没进 PHP 前就打掉明显异常流量
- 把命中 `429` 的 IP 交给 `fail2ban`

### 9.2 Wallos 程序内负责

- 登录失败次数限制
- 普通用户后端请求限制
- 普通用户图片上传/下载次数与流量限制
- 异常事件记录到后台，方便管理员查看

### 9.3 当前建议

- 继续使用你现在程序内的“推荐常规速率限制预设”
- 管理员账号保持应用层豁免
- 管理员安全主要依赖：
  - 强密码
  - 2FA
  - VPS 层限速
  - `fail2ban`
  - 必要时给后台入口加 IP 白名单

---

## 十、管理员后台是否要再单独做一道保护

强烈建议要。

因为你已经明确不希望管理员被应用层限速，所以后台入口最好额外再加一层。

你可以选其中一种：

1. 最稳妥：只允许你自己的固定 IP 访问管理员页
2. 次优：给 `/admin.php` 和 `/endpoints/admin/` 再挂一层 `nginx` Basic Auth
3. 再次优：至少确保管理员账号启用 2FA

如果你经常移动网络，固定 IP 不现实，那么推荐：

- 普通登录页面照常开放
- 管理员账号务必启用 2FA
- 管理员相关路径用更严格的 Nginx 限速

例如你还可以单独加：

```nginx
location = /admin.php {
    limit_req zone=wallos_login_zone burst=5 nodelay;
    proxy_pass http://wallos_frp_upstream;
}

location ^~ /endpoints/admin/ {
    limit_req zone=wallos_api_zone burst=20 nodelay;
    proxy_pass http://wallos_frp_upstream;
}
```

注意：

- 这类规则是“保护入口节奏”
- 不是按管理员身份做业务限速
- 和 Wallos 应用层里“管理员豁免统计”并不冲突

---

## 十一、推荐的最终参数起步值

如果你现在是中小规模自建站，可以先用下面这套：

### 11.1 Nginx 网关层

- 登录页：`10r/m`
- 注册页：`5r/m`
- 找回密码页：`5r/m`
- 后端接口：`180r/m`
- 图片访问：`150r/m`
- 单 IP 并发连接：`30`

### 11.2 Fail2ban

- 全站连续 `429`：10 分钟 20 次，封 1 小时
- 登录/注册/找回密码 `429`：10 分钟 8 次，封 2 小时

### 11.3 Wallos 应用层

保持你当前已经做好的推荐常规值：

- 登录失败阈值：6 次
- 登录失败封禁：30 分钟
- 后端请求：120 次/分钟，1800 次/小时
- 图片上传：4 张/分钟，40 张/小时
- 图片上传流量：40MB/分钟，320MB/小时
- 图片下载：120 次/分钟，1200 次/小时
- 图片下载流量：180MB/分钟，1800MB/小时

如果未来发现很多正常用户共用一个出口 IP，可以优先提高 Nginx 的 `api/media` 限速，而不是先放开 Wallos 应用层限速。

---

## 十二、上线后必须做的检查

不要只看页面能打开，要按下面顺序核对。

### 12.1 检查 Nginx 配置语法

```bash
sudo nginx -t
```

### 12.2 检查 Nginx 是否已经重载

```bash
sudo systemctl reload nginx
sudo systemctl status nginx
```

### 12.3 检查 Fail2ban 是否正常

```bash
sudo systemctl restart fail2ban
sudo systemctl status fail2ban
sudo fail2ban-client status
```

### 12.4 检查 Wallos 本体是否健康

在你自己的 Wallos 机器上确认：

```bash
curl -I http://127.0.0.1:18282/health.php
```

### 12.5 检查程序内异常记录是否正常

进入 Wallos 管理员后台，检查：

- 速率限制配置是否启用
- 安全异常弹窗里是否能看到命中记录
- 访问日志弹窗里是否能看到对应请求

---

## 十三、常见问题

### 13.1 为什么不建议去限制“页面切换速度”

因为那大多只是浏览器跳页面，本质上没有明确业务价值。

真正应该限制的是：

- 登录尝试
- 接口请求
- 图片资源访问

否则你会得到一堆误伤，而不是更安全。

### 13.2 为什么管理员应用层豁免后，还要再做 Nginx / Fail2ban

因为管理员的保护重点应该前移到网关。

应用层豁免的目的是：

- 不让管理员自己在维护站点时被误限

但公网入口仍然必须有保护，否则匿名流量和恶意 IP 还是能冲击你的入口。

### 13.3 没有 Cloudflare 会不会吃亏

会少掉一层全球边缘防护，但并不代表做不了。

你当前最现实的方案就是：

- 日本东京 VPS 上的 Nginx
- `fail2ban`
- 源站不直暴露
- Wallos 应用层限速

这已经能覆盖绝大多数中小规模恶意刷站场景。

---

## 十四、建议你实际执行的顺序

不要一次改太多，按下面顺序推进最稳：

1. 确认物理机源站不能被公网直连
2. 给 VPS 上的 Wallos 站点单独拆出访问日志
3. 先上 Nginx `limit_req` 和 `limit_conn`
4. 用 `curl` 做小规模验证，确认能看到 `429`
5. 再接入 `fail2ban`
6. 再验证封禁确实生效
7. 最后回到 Wallos 管理员后台，确认程序内异常记录也正常

如果你后面要继续深化，可以再做：

- 管理员入口 IP 白名单
- Nginx Basic Auth 保护后台
- 单独的 `/admin.php` 更严限速
- 更细的日志分析与告警

---

## 十五、你后面如果要我继续做什么

如果你希望我继续落地，我下一步最适合直接帮你做的是：

1. 按你当前目录结构，再写一份“日本东京 VPS 的 Nginx 完整可复制配置模板”
2. 再写一份“Fail2ban 完整可复制配置模板”
3. 如果你给我你现在 VPS 上的 Wallos Nginx 配置，我可以继续帮你按现状改成可直接上线的版本

