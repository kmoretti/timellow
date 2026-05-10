# Timellow

一个偏微信风格的 WordPress 主题，面向轻量内容流、前端发布、评论互动和简单社交展示。

![Timellow Screenshot](./screenshot.png)

## 特性

- 微信朋友圈风格的信息流展示
- 前端撰写与发布说说
- 支持从媒体库选择最多 9 张图片，或 1 个视频
- 支持公开 / 私密发布
- 后台提供“说说”内容类型
- 文章在首页以分享链接卡片展示
- 支持置顶说说
- 支持广告标记
- 支持评论、楼中楼回复和游客身份缓存
- 支持点赞，并单独维护点赞数据表
- 支持友链弹窗展示
- 支持顶部背景图 / 背景视频
- 支持自定义 CSS、JavaScript 和统计代码
- 支持腾讯地图定位，展示格式为更简洁的 `城市·地点`

## 环境要求

- WordPress 6.0+
- PHP 7.4+
- 已测试到 WordPress 6.8

## 安装

1. 将主题目录放入 `wp-content/themes/timellow`
2. 在 WordPress 后台启用 `Timellow`
3. 首次启用后，主题会自动创建点赞表 `{$wpdb->prefix}timellow_likes`
4. 进入 `外观 -> 自定义 -> 主题设置` 完成基础配置

## 主题设置

可在 `外观 -> 自定义 -> 主题设置` 中配置：

- 顶部背景视频
- 顶部背景图片
- 头像点击跳转链接
- 头像镜像地址
- 腾讯地图 API Key
- 备案信息
- 列表页自动收起正文
- 自定义 CSS
- 自定义 JavaScript
- 统计代码

### 腾讯地图定位配置

如果要启用前端“所在位置”：

1. 在腾讯位置服务申请 API Key
2. 确保已启用 WebService / 地理编码相关能力
3. 将 Key 填入主题设置中的 `腾讯地图 API Key`

前端撰写定位成功后，会优先展示为：

- `武汉·黄鹤楼`
- `深圳·腾讯滨海大厦`

如果只拿到泛化的行政区或道路信息，则会尽量简化，避免显示过长的完整地址。

## 主要功能说明

### 前端发布

- 仅登录且具备 `edit_posts` 权限的用户可在前端发布说说
- 支持公开和私密可见性
- 支持说说置顶
- 支持广告内容标记
- 支持媒体库选择图片 / 视频

### 评论与互动

- 支持前端发表评论和回复
- 游客评论身份会缓存在浏览器本地
- 点赞数据独立存储，不依赖评论系统

### 友链

主题启用了 WordPress Link Manager，可直接读取书签 / 友链数据。

## REST 接口

主题通过统一入口提供交互接口：

- `GET/POST /wp-json/timellow/v1/action?do=getlikes`
- `GET/POST /wp-json/timellow/v1/action?do=like`
- `GET/POST /wp-json/timellow/v1/action?do=addcomment`
- `GET/POST /wp-json/timellow/v1/action?do=createpost`
- `GET/POST /wp-json/timellow/v1/action?do=getfriendlinks`

这些接口主要供主题前端脚本使用。

## 目录结构

- [`functions.php`](./functions.php)：主题主逻辑、主题设置、REST 接口、前端发布、评论、点赞
- [`header.php`](./header.php)：页面头部与评论交互脚本注入
- [`components/modals/write.php`](./components/modals/write.php)：前端撰写弹窗
- [`components/post/post-position.php`](./components/post/post-position.php)：位置展示
- [`assets/css/timellow.css`](./assets/css/timellow.css)：主题主要样式
- [`assets/js/timellow.js`](./assets/js/timellow.js)：前端交互脚本

## 开发说明

- 这是一个传统 WordPress 主题仓库，不依赖 Node.js 构建流程
- 前端资源以静态文件形式直接维护在仓库中
- 修改 PHP、CSS、JS 后通常刷新页面即可看到结果
- 若改动涉及主题设置、REST 接口或发布流程，优先查看 [`functions.php`](./functions.php)

## 自动发版

仓库已包含 GitHub Actions 工作流 [`release-theme.yml`](./.github/workflows/release-theme.yml)，用于自动生成 WordPress 可安装的主题包。

发版步骤：

1. 修改 [`style.css`](./style.css) 中的 `Version`
2. 如有需要，同步调整 [`functions.php`](./functions.php) 中的 `TIMELLOW_THEME_VERSION`
3. 提交并推送代码
4. 创建并推送对应 tag，例如 `v1.0.11`
5. GitHub Actions 会自动创建或更新对应 Release，并上传 `timellow.zip`

这个 `timellow.zip` 会使用 `timellow/` 作为压缩包根目录，可直接用于 WordPress 后台安装或自动更新。

## 版本信息

- Theme Name: `Timellow`
- Version: `1.0.11`
- Author: `imsun`
- Theme URI: <https://imsun.de>

## License

当前仓库未单独声明许可证；如需开源分发，建议补充明确的 LICENSE 文件。
