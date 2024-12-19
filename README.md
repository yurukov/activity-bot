# ActivityBot - A Simple ActivityPub Bot Server in a Single PHP File

Forked from Terence Eden at https://gitlab.com/edent/activity-bot

This is a single PHP file - and an `.htaccess file` - which acts as an extremely basic ActivityPub server for running automated accounts.

This bot can do the following:

* 🔍 Be discovered on the Fediverse
* 👉 Be followed by other accounts
* 🚫 Be unfollowed by accounts
* 📩 Send messages to the Fediverse
* 💌 Send direct messages to users
* 🖼️ Attach an image & alt text to a message
* 🕸️ Autolink URls, hashtags, and @ mentions
* 👈 Follow, Unfollow, Block, and Unblock other accounts
* 🦋 ~~Bridge to BlueSky with your domain name via Bridgy Fed~~
* 🚚 Move followers from an old account
* 🔏 Verify cryptographic signatures
* 🪵 Log sent messages and errors
* Receive messages (other than follows and unfollows)
* Receive likes and shares

That's it! Here's what it *doesn't* do:

* ❌ Thread replies
* ❌ Delete or update posts
* ❌ Create Polls
* ❌ Attach multiple images
* ❌ Set focus point for images
* ❌ Set sensitivity for images / blur
* ❌ Set "Content Warning"
* ❌ Accurate support for converting user's text to HTML
* ❌ Cannot be discovered by Lemmy instances

## Details on changes from forked version
### Bridge to BlueSky
Handling multiple accounts on the same host via .well-known/atproto-did is not a good idea and requires changes to your server config anyway. Therefore a DNS approach is better. Bluesky offers a [guide](https://bsky.social/about/blog/4-28-2023-domain-handle-tutorial). Just add a DNS entry like *_atproto.username.subdomain TXT     
 "did=did:plc:your-did-number"* for each username and your done. After you follow *@bsky.brid.gy@bsky.brid.gy* wait a day and then DM them with *"content=username {username}.{dimain}"* can get the DID number from *https://fed.brid.gy/ap/@username@domain*. Example below. You can debug the result on [this page](https://bsky-debug.app/handle).

### Multiple accounts
The big change from the original is to add support for multiple accounts. This is done by adding all actions, inboxes, etc. to the server/@username path. Also webfinger takes into account the username being searched for. All this is done by having separate folder with the usename under the "u" subfolder. Details how to set this up are listed below.

## Getting started

This is designed to be a lightweight educational tool to show you the basics of how ActivityPub works.

There are no tests, no checks, no security features, no formal verifications, no containers, no gods, no masters.

### Set Up

1. Create a "u" folder with a subfolder with the exact username you wish to add on the target server
1. Copy the .env file from .env.example in the u/UserName folder, change the details including public and private key
1. Copy the logo and banner images in u/UserName or custom ones. 
1. Upload `index.php` and `.htaccess` to the *root* directory of your domain. For example `test.example.com/`. It will not work in a subdirectory.
1. Optionally, upload an `icon.png` to make the user look nice.
1. Visit `https://test.example.com/.well-known/webfinger?resource=acct:UserName@test.example.com` and check that it shows a JSON file with your user's details.
1. Go to Mastodon or other Fediverse site and search for your user: `@username@test.example.com`
1. Follow your user.
1. Check your `u/UserName/data/logs/` directory to see if the follow request was received correctly.
1. Repeat steps 1,2 and 3 for any number of other usernames you'd like to add
1. Optionally, for the HTML page change the details on the functions *print_header*, *print_sidebar* and *print_footer*

### Post a message

1. To post a message, run this Python code:
```python
import requests
files = { "image": open("banner.png", "rb") }
data = { "password": "your-password-here", "content": "Yet another test\nWith an image!", "alt": "A picture" }
r = requests.post("https://test.example.com/@UserName/action/send", data=data, files=files)
```

### Check it has worked

1. Check social media to see if the message appears.
1. To read the messages that your server's has sent, visit `https://test.example.com/`

### Follow an account

1. To send a Follow message:
```python
import requests
data = { "password": "your-password-here", "action": "Follow", "user": "@name@example.com" }
r = requests.post("https://test.example.com/@UserName/action/users", data=data)
```
2. To create a bridge to Bluesky:
```python
import requests
data = { "password": "your-password-here", "action": "Follow", "user": "@bsky.brid.gy@bsky.brid.gy" }
r = requests.post("https://test.example.com/@UserName/action/users", data=data)
```

### Post a Direct Message

1. To post a DM:
```python
import requests
data = { "password": "your-password-here", "content": "Shhh!", "DM": "@user@whatever.tld" }
r = requests.post("https://test.example.com/@UserName/action/send", data=data)
```
2. Send a message to Bluesky bridge to change username:
```python
import requests
data = { "password": "your-password-here", "content": "username UserName.test.example.com", "DM": "@bsky.brid.gy@bsky.brid.gy"" }
r = requests.post("https://test.example.com/@UserName/action/send", data=data)
```



## How this works

* The `.htaccess` file transforms requests from `example.com/whatever` to `example.com/index.php?path=whatever`.
* The `index.php` file performs a specific action depending on the path requested.
* Log files are saved as .txt in the `/data/logs` directory.
* Post files are saved as .json in the `/posts` directory.
* Details of accounts who follow you are saved as .json in the `/data/followers` directory.
* Messages sent to your inbox are saved as .json in the `/data/inbox` directory.
* This has sloppy support for sending posts with linked #hashtags, https:// URls, and @ mentions.
* HTTP Message Signatures are verified.

##	Requirements

* PHP 8.3 (We live in the future now)
* The [OpenSSL Extension](https://www.php.net/manual/en/book.openssl.php) (This is usually installed by default)
* HTTPS certificate (Let's Encrypt is fine)
* 100MB free disk space (ActivityPub is a very "chatty" protocol. Expect lots of logs.)
* Docker, Node, MongoDB, Wayland, GLaDOS, React, LLM, Adobe Creative Cloud, Maven (Absolutely none of these!)

## Licence

This code is released to you under the [GNU Affero General Public License v3.0 or later](https://www.gnu.org/licenses/agpl-3.0.html).  This means, if you modify this code and let other people interact with it over a computer network, [you **must** release the source code](https://www.gnu.org/licenses/gpl-faq.html#UnreleasedModsAGPL).

I actively do *not* want you to use this code in production. It is not suitable for anything other than educational use.  The use of AGPL is designed to be an incentive for you to learn from this software and then write something better.

Please take note of [CRAPL v0](https://matt.might.net/articles/crapl/):

> Any appearance of design in the Program is purely coincidental and should not in any way be mistaken for evidence of thoughtful software construction.

## Feedback

* Please raise issues at https://gitlab.com/edent/activity-bot/-/issues
* Or contact me on Mastodon `@edent@mastodon.social`

## FAQs

From Terence Eden.

### Why doesn't this store replies?

Moderation is hard. When someone replies to you, they store data on your server. That's fine if the data is "LOL! Cool post!" but it is bad if it is "Here is a link to buy an illegal substance http://..."

Do you want to check every message you've received to make sure you're not being a vector for spam?

What happens if someone posts something clearly illegal to your server and then calls the cops on you?

### Will this scale if I have ten thousand followers?

Dunno. Try it and find out.  If your bot is ridiculously popular, you may want to find a better piece of software to host it.

### Why doesn't it support this very important feature that I want?

Because you haven't sent a Pull Request.

I'm not magic. I'm just one person building small tools. I'm happy to help you understand the code I've written but, if you want something in this world, you have to build it yourself.

### How do I run multiple bots with this?

You don't. Every bot needs its own domain name.

This is a *deliberate* choice. Let each bot have its own space on the Internet.

I don't want you to go and buy lots of new domains - you should create free subdomains.

*Update Boyan Yurukov:* I needed something else, so here we go....

### Why is this built in PHP rather than...?

Because I hate you and want you to suffer.

But, more specifically, because *everything* supports PHP. You can FTP these files onto any host and be guaranteed they'll run.  People don't want to faff around with an NPM install, or setting up a Python VENV.

### Can I use nginx?

Sure, read [README_nginx.md](./README_nginx.md).
