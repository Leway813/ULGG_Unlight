<p align="center">
  <img src="assets/banner.png" alt="UL.GG Unlight Analytics Banner" width="800">
</p>

# UL.GG — Unlight Analytics

UL.GG 是一套針對 **Unlight 對戰資料、牌組資訊與戰鬥狀態** 建立的分析平台。

專案目前包含：

* Unlight 對戰紀錄匯入與資料整理
* MySQL 對戰資料儲存
* PHP 網站與排行榜介面
* BP／QP 排名資料更新
* 卡片與角色資料分析

📌 正式網站：[UL.GG](https://ulgg.online/)

---

## 主要系統

### 1. UL.GG 網站

使用 PHP、MySQL 與前端 JavaScript 建立的 Unlight 資料分析網站。

主要功能包括：

* 玩家對戰紀錄查詢
* 排行榜與分數統計
* BP／QP 資料整理
* 角色使用率與牌組分析
* COST 與卡片資料查詢
* 對戰組合與戰績比較

---

### 2. 對戰資料 Watcher

Watcher 負責讀取遊戲房間與排名資料，並將資料交由匯入程序處理。

主要用途：

* 監看房間資料
* 更新對戰 JSON
* 匯入 MySQL
* 保存排名資料
* 分離不同伺服器與房間來源
* 提供後續網站統計資料

相關程式主要位於：

```text
steam/data/
watcher/
```

---

## 專案結構

```text
ULGG_Unlight/
├─ assets/                         # 網站圖片、CSS、JavaScript 與前端資產
├─ database/                       # 資料庫結構
├─ steam/data/                     # Steam watcher 與資料匯入工具
├─ tool/
│  └─ unlight-card-tracker/        # 本機對戰 Tracker
├─ README.md
└─ .gitignore
```

部分網站新架構與維運工具仍在整理中，尚未全部納入目前公開版本。

---

## 安裝需求

網站端：

* PHP 7.4+
* MySQL 5.7+ 或 MariaDB
* Web Server，例如 Apache 或 Nginx

資料處理與 Tracker：

* Python 3.9+
* Node.js
* Chromium／Chrome DevTools Protocol 相容客戶端
* Windows PowerShell
* PyInstaller，僅建立 Launcher 時需要

實際 Python 套件請參考：

```text
tool/unlight-card-tracker/detector/requirements.txt
tool/unlight-card-tracker/detector/requirements-build.txt
```

---

## 複製專案

```bash
git clone git@github.com:Leway813/ULGG_Unlight.git
cd ULGG_Unlight
```

---

## 環境與敏感資料

以下內容不應提交至 Git：

* `.env`
* SSH private key
* `.ppk`、`.pem`、`.key`
* HAR 流量擷取檔
* runtime JSON／JSONL
* Tracker build output
* 本機資料庫
* debug capture
* watcher backup
* log

請使用範例設定檔建立自己的本機環境，不要將正式密碼、Cookie、Token 或金鑰提交至公開 repository。

---

## 專案狀態

目前專案仍在持續開發。

---

## 使用聲明

本專案為 Unlight 社群研究、對戰分析與工具開發用途。

遊戲名稱、角色、卡片、圖片及相關內容之權利屬於其原權利人。本專案與原遊戲營運方不存在官方合作或授權關係。

請勿將本工具用於破壞遊戲服務、繞過安全機制或違反遊戲使用規範的用途。
