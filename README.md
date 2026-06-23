# DocuWise 🤖📄

DocuWise is a persistent Retrieval-Augmented Generation (RAG) web analytics portal designed to systematically parse, chunk, validate, and execute complex semantic queries over multi-document PDF uploads using the Gemini API[cite: 1].

---

## 🚀 Key Features
* **Multi-Document Parsing:** Streamlined file ingestion pipeline optimized to process structural layouts from multi-page PDFs[cite: 1].
* **Semantic Text Chunking:** Implements logical text splitting algorithms to ensure context retention and token-efficient prompt delivery[cite: 1].
* **Knowledge Base Persistence:** Architected backend data structures for storing document reference IDs and vector-adjacent data[cite: 1].
* **Intelligent Query Execution:** Interfaces directly with the Gemini API to run context-aware, low-latency analytics against uploaded corpora[cite: 1].

---

## 🛠️ Built With
* **AI Engine:** Gemini API[cite: 1]
* **Backend:** Python / PHP (CodeIgniter Architecture)[cite: 1]
* **Database:** MySQL (Schema optimization and indexing)[cite: 1]
* **DevOps Environment:** Linux, Nginx configurations[cite: 1]

---

## ⚙️ Core Architecture Overview
The application handles document processing through a structured backend lifecycle:
1. **Ingestion & Validation:** Secure PDF file uploads are verified for MIME-type safety and processed into byte streams[cite: 1].
2. **Chunking & Indexing:** Text content is split into overlapping semantic segments to prevent loss of context across page boundaries[cite: 1].
3. **Contextual Retrieval:** User natural-language queries are evaluated against indexed document data IDs before interacting with the LLM to minimize hallucination risks[cite: 1].

---

## 🔧 Getting Started

### Prerequisites
* Python 3.10+ / PHP 8.1+
* Gemini API Key
* MySQL Server Instance

### Installation & Local Setup
1. Clone the repository:
```bash
   git clone [https://github.com/koome1400/DocuWise.git](https://github.com/koome1400/DocuWise.git)
   cd DocuWise
