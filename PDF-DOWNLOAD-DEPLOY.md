# 说明书 PDF 下载部署清单（多站复用）

> 适用版本：lisun-manual 插件，2026-11-09 起含「下载 PDF」功能
> 涉及能力：打印版式 v3（@page 页眉页脚）、服务端 Chrome 生成、PDF 书签、附录（软件地址 + 视频二维码）

---

## 1. 功能概览

详情页标题右侧出现「下载 PDF」按钮，点击后由服务端用 chrome-headless-shell 按打印版式渲染当前页面，生成 PDF 并缓存，再以附件下发。

- 首次生成：**约 4–8 秒**（视图片数量），期间按钮显示「生成中…」并禁点
- 二次点击：**< 0.5 秒**（命中缓存）
- 生成时机：正文 `post_modified` 变化后自动重生成
- 并发保护：同一篇 flock + 全局排队（同一时刻只跑 1 份，峰值内存约 1GB/份）

PDF 特性：

| 项 | 说明 |
|---|---|
| 页眉 | 单张合成图（logo + 徽章 + 标题），逐页重复 |
| 页脚 | 左：网址/邮箱图标+文字，中：`页码 / 总页数`，右：电话 |
| 书签 | 按 h1–h6 层级生成，4 级 |
| 文件名 | `产品名 - 操作使用说明书.pdf` / `产品名 - User's Manual.pdf` |
| 附录 | 软件下载地址明文；视频位置换成二维码（指向视频落地页） |

---

## 2. 服务器环境（每台机器做一次）

### 2.1 安装 chrome-headless-shell

需要 **Chrome ≥ 126**（书签开关 `--generate-pdf-document-outline` 的最低版本），当前线上用 159。

```bash
# chrome-for-testing 下载 chrome-headless-shell-linux64.zip，解压到固定目录
mkdir -p /opt/lisun-pdf-poc && cd /opt/lisun-pdf-poc
# 解压后二进制路径示例：
#   /opt/lisun-pdf-poc/chrome-headless-shell-linux64/chrome-headless-shell

# 系统依赖（缺少会启动失败）
dnf install -y mesa-libgbm alsa-lib
# 中文字体（英文站也装，避免中英混排出方框）
dnf install -y google-noto-sans-cjk-fonts
```

验证：

```bash
/opt/lisun-pdf-poc/chrome-headless-shell-linux64/chrome-headless-shell --version
```

### 2.2 放开 PHP 函数

宝塔面板 → 软件商店 → PHP → 设置 → 禁用函数，移除 `exec`、`putenv`（`proc_open` 可继续禁用）。

或直接改 `/www/server/php/<版本>/etc/php.ini` 的 `disable_functions`，然后重载 FPM：

```bash
systemctl reload php-fpm-82    # 版本号按实际
```

> ⚠️ 宝塔的禁用函数是 **FPM 生效**：命令行 `php -r` 测通不代表网页能用，必须走网页验证。

### 2.3 站点 open_basedir 加入 chrome 目录

编辑站点根目录 `.user.ini`，在 `open_basedir` 末尾追加：

```
:/opt/lisun-pdf-poc/
```

该文件常被 `chattr +i` 锁定，写入会**静默失败**，必须先解锁：

```bash
cd /www/wwwroot/<站点>
chattr -i .user.ini
# 修改 open_basedir
chattr +i .user.ini
```

> 缺这一步的典型报错：`服务器未找到 chrome-headless-shell`（`file_exists` 被 open_basedir 拦成 false）。

### 2.4 其它

- `wp-content/uploads` 可写（缓存目录 `uploads/lisun-pdf/`，程序自动创建并写 `.htaccess` 拒绝直连）
- 磁盘余量：每份 PDF 约 **3–7MB**，按常下载的手册数量估算
- 内存：单次渲染峰值约 **1GB**；2 核 2G 以下机器建议不要开

---

## 3. 文件清单

| 类型 | 路径 | 说明 |
|---|---|---|
| 新增 | `includes/class-pdf-download.php` | 下载入口、令牌、缓存、chrome 生成、全局排队 |
| 新增 | `assets/js/qrcode.js` | qrcode-generator 1.4.4（MIT），本地引入不依赖 CDN |
| 新增 | `assets/img/print-header.png` | 页眉合成图（186×27.52mm @300dpi） |
| 新增 | `assets/img/icon-globe.png` | 页脚网址图标 |
| 新增 | `assets/img/icon-mail.png` | 页脚邮箱图标 |
| 修改 | `lisun-manual.php` | require + `init()`；`front.css` 版本号改 `filemtime`；入队 qrcode.js |
| 修改 | `templates/single-manual.php` | 按钮与下载状态脚本；`@page` 页眉语言文字；附录明文地址/二维码；附录子标题改 `h2` |
| 修改 | `assets/css/front.css` | `@page` 版式块；打印隐藏按钮；附录打印规则 |

> 若目标站插件版本与本源不同，请**逐文件对比合并**，不要整包覆盖，避免丢对方的定制。

---

## 4. 站点配置（只改配置，不改代码）

### 4.1 chrome 路径（可选）

插件默认读 `/opt/lisun-pdf-poc/chrome-headless-shell-linux64/chrome-headless-shell`。
实际安装位置不同时，在 `wp-config.php` 加一行即可：

```php
define('LISUN_MANUAL_CHROME_BIN', '/your/path/chrome-headless-shell');
```

也可在主题/插件里用 filter 覆盖：

```php
add_filter('lisun_manual_chrome_bin', fn() => '/your/path/chrome-headless-shell');
```

### 4.2 语言

`_lisun_manual_lang`（文章级）→ 站点选项 `lisun_page_lang` → 兜底 `zh`，决定：

- 页眉文字：`User's Manual` / `操作使用说明书`
- 按钮文案：`Download PDF` / `下载 PDF`
- PDF 文件名后缀：` - User's Manual` / ` - 操作使用说明书`

### 4.3 密码门

沿用插件现有 Access Control（`Lisun_Manual_Access_Control::access_check`），无需改动。
下载流程内部走 120 秒一次性令牌，chrome 渲染时以本机（127.0.0.1）+ 令牌绕过密码门，避免把登录页打成 PDF。

---

## 5. 验收清单

1. 详情页标题右侧出现按钮，副标题仍水平居中
2. 点击 → 按钮变「生成中…」并禁点 → 触发下载；文件名符合语言规则
3. 连续点击不会被重复触发；二次点击 < 1s
4. PDF 检查：
   - 页眉 logo/徽章/标题齐全，每页一致
   - 页脚网址、邮箱、电话、`页码 / 总页数` 正常
   - 书签面板层级正确，可跳转
   - 末页附录：软件显示明文地址，视频位置是二维码
5. 手机扫码二维码能打开对应视频
6. 浏览器 Ctrl+P 与下载的 PDF 版式一致

---

## 6. 排错速查

| 现象 | 原因 | 处理 |
|---|---|---|
| 报「未找到 chrome-headless-shell」 | `open_basedir` 不含 chrome 目录，或路径不对 | 见 2.3 / 4.1 |
| 报「已禁用 exec()」 | FPM 的 `disable_functions` 未放开 | 见 2.2，改完重载 FPM |
| 生成出 0 字节 / 极小文件 | chrome 启动失败（缺库/无字体/无沙箱权限） | 手动跑一次命令看 stderr |
| 中文变方框 | 未装 CJK 字体 | `dnf install google-noto-sans-cjk-fonts` |
| 改了样式但打印没变 | 浏览器/CDN 缓存旧 `front.css` | 已用 `filemtime` 版本号；CDN 需手动刷一次 |
| 提示「排队超时，请稍后重试」 | 同时生成过多 | 正常保护，重试即可 |
| 页面出现两个页眉 | 主题或旧规则强制显示了 HTML 页眉 | 确保 `front.css` 中 `.lisun-print-header { display:none !important }` |

---

## 7. 运维

- **缓存**：`wp-content/uploads/lisun-pdf/{post_id}-{md5}.pdf`
  - 键含 `CACHE_VER`，`class-pdf-download.php` 里生成逻辑变更时 +1 即可整体失效
  - 新文件生成后会删除同文章旧版本文件
  - 无自动过期策略，手册量大时可加定时任务清理 30 天未访问的缓存
- **排队**：`GEN_WAIT`（默认 90 秒）控制排队上限，超时直接失败提示重试
- **升级 chrome**：替换二进制后无需改代码（版本 ≥126 即可）
